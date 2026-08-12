<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Contracts\LeadTransmitter;
use App\Domain\LeadProcessing\Data\TransmissionResult;
use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\Exceptions\LeadTransmissionException;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use App\Services\Leads\LeadProcessor;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class LeadProcessingPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_pipeline_records_ordered_statuses_and_events_and_transmits_before_routed(): void
    {
        $lead = $this->newLead('pipeline-success-001');
        $transmitter = new ControllableLeadTransmitter(function (Lead $transmittedLead): TransmissionResult {
            $persisted = $transmittedLead->fresh();
            $this->assertSame(LeadStatus::Qualified, $persisted->status);
            $this->assertNull($persisted->processed_at);
            $this->assertDatabaseMissing('processing_events', [
                'lead_id' => $persisted->id,
                'type' => ProcessingEventType::LeadRouted->value,
            ]);

            return TransmissionResult::succeeded('simulated-test-success');
        });

        $this->processorUsing($transmitter)->process($lead->id);
        $lead->refresh();

        $this->assertSame(LeadStatus::Routed, $lead->status);
        $this->assertSame(LeadClassification::Hot, $lead->classification);
        $this->assertSame('Sarah', $lead->assigned_to);
        $this->assertSame(80, $lead->score);
        $this->assertSame(1, $lead->processing_attempts);
        $this->assertNotNull($lead->processed_at);
        $this->assertSame(1, $transmitter->calls);
        $this->assertSame('lead:'.$lead->id.':transmission', $transmitter->idempotencyKeys[0]);

        $this->assertSame([
            'processing.started',
            'lead.normalized',
            'validation.succeeded',
            'duplicate.checked',
            'score.calculated',
            'lead.classified',
            'lead.routing_decided',
            'lead.qualified',
            'transmission.succeeded',
            'lead.routed',
        ], $this->eventTypes($lead));

        $transitions = $lead->processingEvents()
            ->whereNotNull('to_status')
            ->get()
            ->map(fn ($event): array => [$event->from_status->value, $event->to_status->value])
            ->all();
        $this->assertSame([
            ['RECEIVED', 'VALIDATING'],
            ['VALIDATING', 'QUALIFIED'],
            ['QUALIFIED', 'ROUTED'],
        ], $transitions);

        $transmittedEvent = $lead->processingEvents()->where('type', 'transmission.succeeded')->sole();
        $routedEvent = $lead->processingEvents()->where('type', 'lead.routed')->sole();
        $this->assertLessThan($routedEvent->id, $transmittedEvent->id);
    }

    public function test_unknown_service_becomes_needs_review_without_classification_routing_or_transmission(): void
    {
        $lead = $this->newLead('pipeline-review-001', ['service_interest' => 'custom_service']);
        $transmitter = new ControllableLeadTransmitter(
            fn (): TransmissionResult => TransmissionResult::succeeded('must-not-run'),
        );

        $this->processorUsing($transmitter)->process($lead->id);
        $lead->refresh();

        $this->assertSame(LeadStatus::NeedsReview, $lead->status);
        $this->assertNull($lead->classification);
        $this->assertNull($lead->assigned_to);
        $this->assertNull($lead->processed_at);
        $this->assertSame(0, $transmitter->calls);
        $this->assertSame([
            'processing.started',
            'lead.normalized',
            'review.requested',
        ], $this->eventTypes($lead));
        $this->assertSame(
            ['service_interest.unknown'],
            $lead->processingEvents()->where('type', 'review.requested')->sole()->context['reason_codes'],
        );
    }

    public function test_unique_prior_match_becomes_duplicate_and_ambiguous_matches_need_review(): void
    {
        $emailOrigin = $this->newLead('origin-email-001', [
            'email' => 'origin-email@example.test',
            'normalized_email' => 'origin-email@example.test',
            'phone' => '+12025550121',
            'normalized_phone' => '+12025550121',
            'status' => LeadStatus::Routed,
            'processed_at' => now(),
            'received_at' => now()->subMinutes(2),
        ]);
        $phoneOrigin = $this->newLead('origin-phone-001', [
            'email' => 'origin-phone@example.test',
            'normalized_email' => 'origin-phone@example.test',
            'phone' => '+12025550122',
            'normalized_phone' => '+12025550122',
            'status' => LeadStatus::Routed,
            'processed_at' => now(),
            'received_at' => now()->subMinute(),
        ]);
        $duplicate = $this->newLead('duplicate-001', [
            'email' => $emailOrigin->email,
            'phone' => '+12025550123',
        ]);
        $ambiguous = $this->newLead('ambiguous-001', [
            'email' => $emailOrigin->email,
            'phone' => $phoneOrigin->phone,
        ]);
        $transmitter = new ControllableLeadTransmitter(
            fn (): TransmissionResult => TransmissionResult::succeeded('must-not-run'),
        );
        $processor = $this->processorUsing($transmitter);

        $processor->process($duplicate->id);
        $processor->process($ambiguous->id);

        $this->assertSame(LeadStatus::Duplicate, $duplicate->refresh()->status);
        $this->assertSame($emailOrigin->id, $duplicate->duplicate_of);
        $this->assertSame(LeadStatus::NeedsReview, $ambiguous->refresh()->status);
        $this->assertNull($ambiguous->duplicate_of);
        $this->assertSame(0, $transmitter->calls);
    }

    public function test_technical_failures_retry_twice_resume_from_qualified_and_fail_only_after_exhaustion(): void
    {
        $lead = $this->newLead('pipeline-retry-001');
        $transmitter = new ControllableLeadTransmitter(
            fn (): TransmissionResult => TransmissionResult::failed('simulated_unavailable'),
        );
        $processor = $this->processorUsing($transmitter);
        $job = new ProcessLead($lead->id);

        $this->expectTransmissionFailure(fn () => $processor->process($lead->id));
        $job->failed(new RuntimeException('must not be persisted: private-token-001'));
        $this->assertSame(LeadStatus::Qualified, $lead->refresh()->status);
        $this->assertSame(1, $lead->processing_attempts);

        $this->expectTransmissionFailure(fn () => $processor->process($lead->id));
        $this->expectTransmissionFailure(fn () => $processor->process($lead->id));
        $this->assertSame(LeadStatus::Qualified, $lead->refresh()->status);
        $this->assertSame(3, $lead->processing_attempts);

        $job->failed(new RuntimeException('must not be persisted: private-token-002'));
        $lead->refresh();

        $this->assertSame(LeadStatus::Failed, $lead->status);
        $this->assertNull($lead->processed_at);
        $this->assertSame(3, $transmitter->calls);
        $this->assertSame(1, $lead->processingEvents()->where('type', 'score.calculated')->count());
        $this->assertSame(1, $lead->processingEvents()->where('type', 'lead.classified')->count());
        $this->assertSame(1, $lead->processingEvents()->where('type', 'lead.routing_decided')->count());
        $this->assertSame(3, $lead->processingEvents()->where('type', 'transmission.failed')->count());
        $this->assertSame(2, $lead->processingEvents()->where('type', 'processing.retry_scheduled')->count());
        $this->assertSame(1, $lead->processingEvents()->where('type', 'processing.failed')->count());
        $this->assertSame([1, 2, 3], $lead->processingEvents()
            ->where('type', 'transmission.failed')
            ->pluck('attempt')
            ->all());

        $serializedContexts = $lead->processingEvents()->pluck('context')->toJson();
        $this->assertStringNotContainsString('private-token', $serializedContexts);
        $this->assertStringNotContainsString($lead->email, $serializedContexts);
        $this->assertStringNotContainsString($lead->message, $serializedContexts);
    }

    public function test_unexpected_exception_is_reduced_to_a_safe_error_code(): void
    {
        $lead = $this->newLead('pipeline-safe-error-001');
        $transmitter = new ControllableLeadTransmitter(function (): never {
            throw new RuntimeException('secret-token-and-internal-host');
        });

        try {
            $this->processorUsing($transmitter)->process($lead->id);
            $this->fail('A technical exception should be rethrown for the queue retry mechanism.');
        } catch (RuntimeException $exception) {
            $this->assertSame('secret-token-and-internal-host', $exception->getMessage());
        }

        $context = $lead->processingEvents()->where('type', 'transmission.failed')->sole()->context;
        $this->assertSame(['error_code' => 'technical_failure'], $context);
        $this->assertStringNotContainsString('secret-token', json_encode($context, JSON_THROW_ON_ERROR));
    }

    public function test_terminal_reexecution_is_a_no_op_without_duplicate_events_or_transmission(): void
    {
        $lead = $this->newLead('pipeline-idempotent-001');
        $transmitter = new ControllableLeadTransmitter(
            fn (): TransmissionResult => TransmissionResult::succeeded('simulated-idempotent'),
        );
        $processor = $this->processorUsing($transmitter);

        $processor->process($lead->id);
        $eventCount = $lead->processingEvents()->count();
        $processor->process($lead->id);

        $this->assertSame(LeadStatus::Routed, $lead->refresh()->status);
        $this->assertSame(1, $lead->processing_attempts);
        $this->assertSame($eventCount, $lead->processingEvents()->count());
        $this->assertSame(1, $transmitter->calls);
    }

    public function test_job_uses_database_queue_three_attempts_and_prevents_concurrent_processing(): void
    {
        Queue::fake();
        $job = new ProcessLead(42);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame('database', $job->connection);
        $this->assertSame('lead-processing', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertSame([1, 5], $job->backoff());
        $this->assertSame('lead:42', $job->uniqueId());

        ProcessLead::dispatch(42);
        Queue::assertPushed(ProcessLead::class, fn (ProcessLead $queued): bool => $queued->connection === 'database'
            && $queued->queue === 'lead-processing'
            && $queued->leadId === 42);

        $firstMiddleware = $job->middleware()[0];
        $secondMiddleware = (new ProcessLead(42))->middleware()[0];
        $firstQueuedJob = new QueueJobProbe;
        $secondQueuedJob = new QueueJobProbe;
        $secondRan = false;

        $firstMiddleware->handle($firstQueuedJob, function () use ($secondMiddleware, $secondQueuedJob, &$secondRan): void {
            $secondMiddleware->handle($secondQueuedJob, function () use (&$secondRan): void {
                $secondRan = true;
            });
        });

        $this->assertFalse($secondRan);
        $this->assertSame(1, $secondQueuedJob->releasedAfter);
        $this->assertTrue($firstMiddleware->shareKey);
        $this->assertSame(300, $firstMiddleware->expiresAfter);
    }

    private function processorUsing(LeadTransmitter $transmitter): LeadProcessor
    {
        $this->app->instance(LeadTransmitter::class, $transmitter);

        return $this->app->make(LeadProcessor::class);
    }

    /** @param array<string, mixed> $overrides */
    private function newLead(string $reference, array $overrides = []): Lead
    {
        return Lead::factory()->create([
            'external_reference' => $reference,
            'first_name' => '  Prenom   Fictif ',
            'last_name' => '  Nom   Fictif ',
            'company' => 'Entreprise Fictive',
            'email' => "{$reference}@example.test",
            'phone' => '+12025550110',
            'country' => 'US',
            'service_interest' => 'automation',
            'company_size' => 12,
            'message' => 'Message fictif court pour le pipeline local.',
            'consent' => true,
            'received_at' => now(),
            'normalized_email' => null,
            'normalized_phone' => null,
            'score' => null,
            'classification' => null,
            'assigned_to' => null,
            'status' => LeadStatus::Received,
            'duplicate_of' => null,
            'processing_attempts' => 0,
            'processed_at' => null,
            ...$overrides,
        ]);
    }

    /** @return list<string> */
    private function eventTypes(Lead $lead): array
    {
        return $lead->processingEvents()
            ->get()
            ->map(fn ($event): string => $event->type->value)
            ->all();
    }

    private function expectTransmissionFailure(Closure $callback): void
    {
        try {
            $callback();
            $this->fail('The controlled transmission should fail.');
        } catch (LeadTransmissionException $exception) {
            $this->assertSame('simulated_unavailable', $exception->errorCode);
        }
    }
}

final class ControllableLeadTransmitter implements LeadTransmitter
{
    public int $calls = 0;

    /** @var list<string> */
    public array $idempotencyKeys = [];

    public function __construct(private Closure $callback) {}

    public function transmit(Lead $lead, string $idempotencyKey): TransmissionResult
    {
        $this->calls++;
        $this->idempotencyKeys[] = $idempotencyKey;

        return ($this->callback)($lead, $idempotencyKey);
    }
}

final class QueueJobProbe
{
    public ?int $releasedAfter = null;

    public function release(int $delay): void
    {
        $this->releasedAfter = $delay;
    }
}
