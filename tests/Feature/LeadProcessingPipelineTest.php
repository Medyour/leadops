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
use App\Services\Leads\SimulatedLeadTransmitter;
use Closure;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class LeadProcessingPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_pipeline_records_ordered_statuses_and_events_and_transmits_before_routed(): void
    {
        $lead = $this->newLead('pipeline-success-001');
        $testTransactionLevel = DB::transactionLevel();
        $transmitter = new ControllableLeadTransmitter(function (Lead $transmittedLead) use ($testTransactionLevel): TransmissionResult {
            $this->assertSame(
                $testTransactionLevel,
                DB::transactionLevel(),
                'The transmitter must not run inside an application transaction.',
            );
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
        $this->assertSame('lead:'.$lead->id.':cycle:initial:transmission', $transmitter->idempotencyKeys[0]);

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
        $this->assertSame(
            ['reference_fingerprint', 'idempotency_key'],
            array_keys($transmittedEvent->context),
        );
        $this->assertSame(16, strlen($transmittedEvent->context['reference_fingerprint']));
        $this->assertStringNotContainsString(
            'simulated-test-success',
            json_encode($transmittedEvent->context, JSON_THROW_ON_ERROR),
        );
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
        $this->assertStringNotContainsString('simulated_unavailable', $serializedContexts);
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

    public function test_lost_transmission_acknowledgement_reuses_the_key_without_a_second_side_effect(): void
    {
        $lead = $this->newLead('pipeline-lost-ack-001');
        $transmitter = new IdempotentLeadTransmitter(loseFirstAcknowledgement: true);
        $processor = $this->processorUsing($transmitter);

        try {
            $processor->process($lead->id);
            $this->fail('The first acknowledgement should be lost after the simulated side effect.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated lost acknowledgement', $exception->getMessage());
        }

        $this->assertSame(LeadStatus::Qualified, $lead->refresh()->status);
        $this->assertSame(1, $lead->processing_attempts);

        $processor->process($lead->id);
        $lead->refresh();

        $this->assertSame(LeadStatus::Routed, $lead->status);
        $this->assertSame(2, $lead->processing_attempts);
        $this->assertSame(2, $transmitter->calls);
        $this->assertSame(1, $transmitter->sideEffects);
        $this->assertCount(1, array_unique($transmitter->idempotencyKeys));
        $this->assertSame(1, $lead->processingEvents()->where('type', 'transmission.succeeded')->count());
        $this->assertSame(1, $lead->processingEvents()->where('type', 'lead.routed')->count());
        $this->assertSame(1, $lead->processingEvents()->where('type', 'score.calculated')->count());
    }

    public function test_simulated_transmitter_uses_the_idempotency_key_deterministically(): void
    {
        $lead = $this->newLead('pipeline-simulated-transmitter-001');
        $transmitter = $this->app->make(SimulatedLeadTransmitter::class);

        $first = $transmitter->transmit($lead, 'lead:'.$lead->id.':transmission');
        $repeated = $transmitter->transmit($lead, 'lead:'.$lead->id.':transmission');
        $different = $transmitter->transmit($lead, 'lead:'.$lead->id.':another-transmission');

        $this->assertTrue($first->successful);
        $this->assertSame($first->reference, $repeated->reference);
        $this->assertNotSame($first->reference, $different->reference);
    }

    public function test_transmission_event_and_routed_transition_roll_back_together_when_finalization_fails(): void
    {
        $lead = $this->newLead('pipeline-finalization-001');
        $transmitter = new IdempotentLeadTransmitter;
        $processor = $this->processorUsing($transmitter);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_lead_routed_event
            BEFORE INSERT ON processing_events
            WHEN NEW.type = 'lead.routed'
            BEGIN
                SELECT RAISE(ABORT, 'simulated finalization failure');
            END
            SQL);

        try {
            $processor->process($lead->id);
            $this->fail('The routed event trigger should abort finalization.');
        } catch (QueryException) {
            // The transmission has succeeded, but its local finalization is atomic and rolls back.
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_lead_routed_event');
        }

        $lead->refresh();
        $this->assertSame(LeadStatus::Qualified, $lead->status);
        $this->assertNull($lead->processed_at);
        $this->assertSame(1, $lead->processing_attempts);
        $this->assertSame(0, $lead->processingEvents()->where('type', 'transmission.succeeded')->count());
        $this->assertSame(0, $lead->processingEvents()->where('type', 'lead.routed')->count());
        $this->assertSame(1, $lead->processingEvents()->where('type', 'processing.attempt_failed')->count());

        $processor->process($lead->id);
        $lead->refresh();

        $this->assertSame(LeadStatus::Routed, $lead->status);
        $this->assertSame(2, $lead->processing_attempts);
        $this->assertSame(2, $transmitter->calls);
        $this->assertSame(1, $transmitter->sideEffects);
        $this->assertCount(1, array_unique($transmitter->idempotencyKeys));
        $this->assertSame(1, $lead->processingEvents()->where('type', 'transmission.succeeded')->count());
        $this->assertSame(1, $lead->processingEvents()->where('type', 'lead.routed')->count());
    }

    public function test_transition_event_failure_restores_the_model_status_before_recording_attempt_failure(): void
    {
        $lead = $this->newLead('pipeline-transition-rollback-001');
        $transmitter = new ControllableLeadTransmitter(
            fn (): TransmissionResult => TransmissionResult::succeeded('simulated-after-retry'),
        );
        $processor = $this->processorUsing($transmitter);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_lead_qualified_event
            BEFORE INSERT ON processing_events
            WHEN NEW.type = 'lead.qualified'
            BEGIN
                SELECT RAISE(ABORT, 'simulated qualified event failure');
            END
            SQL);

        try {
            $processor->process($lead->id);
            $this->fail('The qualified event trigger should abort the transition.');
        } catch (QueryException) {
            // The attempt failure remains journaled from the rolled-back VALIDATING state.
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_lead_qualified_event');
        }

        $lead->refresh();
        $failureEvent = $lead->processingEvents()->where('type', 'processing.attempt_failed')->sole();
        $this->assertSame(LeadStatus::Validating, $lead->status);
        $this->assertSame(LeadStatus::Validating, $failureEvent->from_status);
        $this->assertSame(1, $lead->processing_attempts);
        $this->assertSame(0, $transmitter->calls);

        $processor->process($lead->id);

        $this->assertSame(LeadStatus::Routed, $lead->refresh()->status);
        $this->assertSame(2, $lead->processing_attempts);
        $this->assertSame(1, $transmitter->calls);
    }

    public function test_failed_hook_uses_the_final_queue_attempt_but_never_fails_early(): void
    {
        $lead = $this->newLead('pipeline-failed-hook-001', [
            'status' => LeadStatus::Qualified,
            'processing_attempts' => 2,
            'score' => 80,
            'classification' => LeadClassification::Hot,
            'assigned_to' => 'Sarah',
        ]);
        $job = new ProcessLead($lead->id);
        $queueJob = new FakeJob;
        $job->setJob($queueJob);

        $queueJob->attempts = 2;
        $job->failed(new RuntimeException('sensitive exception from an early failure'));
        $this->assertSame(LeadStatus::Qualified, $lead->refresh()->status);
        $this->assertSame(2, $lead->processing_attempts);
        $this->assertDatabaseMissing('processing_events', [
            'lead_id' => $lead->id,
            'type' => ProcessingEventType::ProcessingFailed->value,
        ]);

        $queueJob->attempts = 3;
        $job->failed(new RuntimeException('sensitive exception from the final failure'));
        $lead->refresh();

        $this->assertSame(LeadStatus::Failed, $lead->status);
        $this->assertSame(3, $lead->processing_attempts);
        $this->assertSame(
            ['error_code' => 'attempts_exhausted'],
            $lead->processingEvents()->where('type', 'processing.failed')->sole()->context,
        );
        $this->assertStringNotContainsString(
            'sensitive exception',
            $lead->processingEvents()->pluck('context')->toJson(),
        );
    }

    public function test_reexecution_heals_an_exhausted_cycle_without_duplicate_failure_events(): void
    {
        $lead = $this->newLead('pipeline-exhausted-cycle-001', [
            'status' => LeadStatus::Qualified,
            'processing_attempts' => 3,
            'score' => 80,
            'classification' => LeadClassification::Hot,
            'assigned_to' => 'Sarah',
        ]);
        $transmitter = new ControllableLeadTransmitter(
            fn (): TransmissionResult => TransmissionResult::succeeded('must-not-run'),
        );
        $processor = $this->processorUsing($transmitter);

        $processor->process($lead->id);
        $processor->process($lead->id);

        $this->assertSame(LeadStatus::Failed, $lead->refresh()->status);
        $this->assertSame(3, $lead->processing_attempts);
        $this->assertSame(0, $transmitter->calls);
        $this->assertSame(1, $lead->processingEvents()->where('type', 'processing.failed')->count());
    }

    public function test_job_uses_database_queue_three_attempts_and_prevents_overlap_at_the_cache_layer(): void
    {
        Queue::fake();
        $job = new ProcessLead(42);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame(3, $job->tries);
        $this->assertSame(60, $job->timeout);
        $this->assertSame(300, $job->uniqueFor);
        $this->assertGreaterThan(
            (config('queue.connections.database.retry_after') * ($job->tries - 1)) + $job->timeout,
            $job->uniqueFor,
        );
        $this->assertSame('database', $job->connection);
        $this->assertSame('lead-processing', $job->queue);
        $this->assertTrue($job->afterCommit);
        $this->assertSame([1, 5], $job->backoff());
        $this->assertSame('lead:42:cycle:initial', $job->uniqueId());

        ProcessLead::dispatch(42);
        ProcessLead::dispatch(42);
        Queue::assertPushed(ProcessLead::class, fn (ProcessLead $queued): bool => $queued->connection === 'database'
            && $queued->queue === 'lead-processing'
            && $queued->leadId === 42);
        Queue::assertPushed(ProcessLead::class, 1);

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
        $this->assertNull($secondQueuedJob->releasedAfter);
        $this->assertTrue($firstMiddleware->shareKey);
        $this->assertSame(75, $firstMiddleware->expiresAfter);
        $this->assertGreaterThan($job->timeout, $firstMiddleware->expiresAfter);
        $this->assertLessThan(config('queue.connections.database.retry_after'), $firstMiddleware->expiresAfter);

        $ranAfterSuccess = false;
        (new ProcessLead(42))->middleware()[0]->handle(new QueueJobProbe, function () use (&$ranAfterSuccess): void {
            $ranAfterSuccess = true;
        });
        $this->assertTrue($ranAfterSuccess, 'The overlap lock must be released after success.');

        try {
            (new ProcessLead(43))->middleware()[0]->handle(new QueueJobProbe, function (): never {
                throw new RuntimeException('simulated handler failure');
            });
        } catch (RuntimeException) {
            // The middleware must release its lock from its finally block.
        }

        $ranAfterFailure = false;
        (new ProcessLead(43))->middleware()[0]->handle(new QueueJobProbe, function () use (&$ranAfterFailure): void {
            $ranAfterFailure = true;
        });
        $this->assertTrue($ranAfterFailure, 'The overlap lock must be released after failure.');

        $this->assertSame(
            'sqlite',
            DB::connection()->getDriverName(),
            'SQLite does not provide a real row-lock concurrency test; this test covers the cache lock.',
        );
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

final class IdempotentLeadTransmitter implements LeadTransmitter
{
    public int $calls = 0;

    public int $sideEffects = 0;

    /** @var list<string> */
    public array $idempotencyKeys = [];

    /** @var array<string, TransmissionResult> */
    private array $receipts = [];

    public function __construct(private bool $loseFirstAcknowledgement = false) {}

    public function transmit(Lead $lead, string $idempotencyKey): TransmissionResult
    {
        $this->calls++;
        $this->idempotencyKeys[] = $idempotencyKey;

        if (! isset($this->receipts[$idempotencyKey])) {
            $this->sideEffects++;
            $this->receipts[$idempotencyKey] = TransmissionResult::succeeded(
                'simulated-'.substr(hash('sha256', $idempotencyKey), 0, 16),
            );
        }

        if ($this->loseFirstAcknowledgement) {
            $this->loseFirstAcknowledgement = false;

            throw new RuntimeException('simulated lost acknowledgement');
        }

        return $this->receipts[$idempotencyKey];
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
