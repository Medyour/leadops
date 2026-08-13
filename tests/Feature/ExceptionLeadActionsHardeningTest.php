<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Contracts\LeadTransmitter;
use App\Domain\LeadProcessing\Data\TransmissionResult;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\Exceptions\LeadTransmissionException;
use App\Domain\LeadProcessing\LeadStatusTransition;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use App\Services\Leads\LeadExceptionActionService;
use App\Services\Leads\LeadProcessor;
use App\Services\Leads\ProcessingEventRecorder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExceptionLeadActionsHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('leadops.exception_actions.rate_limit_per_minute', 60);
    }

    public function test_cast_equivalent_and_empty_optional_values_do_not_create_a_correction_event(): void
    {
        Queue::fake();
        $lead = $this->reviewLead([
            'company' => null,
            'message' => null,
            'company_size' => 12,
            'consent' => true,
        ]);

        $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'company' => '',
            'message' => '',
            'company_size' => '12',
            'consent' => '1',
            'unknown' => ['nested' => 'ignored'],
        ])->assertRedirect(route('leads.show', $lead));

        $this->assertDatabaseMissing('processing_events', [
            'lead_id' => $lead->id,
            'type' => ProcessingEventType::ReviewCorrected->value,
        ]);
        $this->assertNull($lead->fresh()->company);
        $this->assertNull($lead->message);
        $this->assertSame(12, $lead->company_size);
        $this->assertTrue($lead->consent);
        Queue::assertNothingPushed();
    }

    public function test_null_empty_boolean_and_integer_changes_are_detected_exactly_once(): void
    {
        Queue::fake();
        $lead = $this->reviewLead([
            'company' => 'Entreprise Fictive',
            'company_size' => 12,
            'message' => 'Message fictif initial.',
            'consent' => true,
        ]);

        $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'company' => '',
            'company_size' => '',
            'message' => '',
            'consent' => '0',
        ])->assertRedirect();

        $event = $lead->processingEvents()->where('type', ProcessingEventType::ReviewCorrected)->sole();
        $this->assertSame(['company', 'company_size', 'message', 'consent'], $event->context['changed_fields']);
        $lead->refresh();
        $this->assertNull($lead->company);
        $this->assertNull($lead->company_size);
        $this->assertNull($lead->message);
        $this->assertFalse($lead->consent);

        $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'company' => '',
            'company_size' => '',
            'message' => '',
            'consent' => '0',
        ])->assertRedirect();

        $this->assertSame(1, $lead->processingEvents()->where('type', ProcessingEventType::ReviewCorrected)->count());
        Queue::assertNothingPushed();
    }

    public function test_unknown_nested_and_system_parameters_are_ignored_while_protected_or_malformed_fields_are_rejected(): void
    {
        Queue::fake();
        $lead = $this->reviewLead();

        $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'id' => 999,
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => ['nested' => 'ignored'],
            'unknown' => ['nested' => ['value' => 'ignored']],
        ])->assertRedirect();

        $this->assertSame($lead->id, $lead->fresh()->id);
        $this->assertDatabaseCount('processing_events', 0);

        $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'status' => ['value' => LeadStatus::Routed->value],
            'source' => ['value' => 'webhook'],
            'normalized_email' => ['value' => 'protected@example.test'],
        ])->assertSessionHasErrors(['status', 'source', 'normalized_email']);

        $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'first_name' => ['nested' => 'invalid'],
        ])->assertSessionHasErrors('first_name');

        $this->assertSame(LeadStatus::NeedsReview, $lead->fresh()->status);
        $this->assertDatabaseCount('processing_events', 0);
        Queue::assertNothingPushed();
    }

    public function test_request_cannot_choose_a_cycle_identifier(): void
    {
        Queue::fake();
        $review = $this->reviewLead();
        $failed = Lead::factory()->failed()->create();
        $suppliedCycle = '00000000-0000-4000-8000-000000000000';

        $this->post(route('leads.reprocess', $review), ['cycle_id' => $suppliedCycle])
            ->assertSessionHasErrors('cycle_id');
        $this->post(route('leads.retry', $failed), ['cycle_id' => $suppliedCycle])
            ->assertSessionHasErrors('cycle_id');

        $this->assertSame(LeadStatus::NeedsReview, $review->fresh()->status);
        $this->assertSame(LeadStatus::Failed, $failed->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_unknown_service_can_be_corrected_and_reprocessed_to_routed_with_stable_timeline_order(): void
    {
        Queue::fake();
        $lead = $this->reviewLead(['service_interest' => 'service_inconnu']);

        $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'service_interest' => 'automation',
        ])->assertRedirect();
        $cycleId = app(LeadExceptionActionService::class)->reprocess($lead);
        app(LeadProcessor::class)->process($lead->id, $cycleId, 1);

        $lead->refresh();
        $this->assertSame(LeadStatus::Routed, $lead->status);
        $this->assertNotNull($lead->score);
        $this->assertNotNull($lead->classification);
        $this->assertSame('Sarah', $lead->assigned_to);
        $this->assertSame([
            ProcessingEventType::ReviewCorrected->value,
            ProcessingEventType::ReviewReprocessingRequested->value,
            ProcessingEventType::ProcessingStarted->value,
        ], $lead->processingEvents()->get()->take(3)->map(fn (ProcessingEvent $event): string => $event->type->value)->all());
        $this->assertSame($cycleId, $lead->processingEvents()->where('type', ProcessingEventType::ProcessingStarted)->sole()->context['cycle_id']);
        $lead->processingEvents()
            ->where('type', '!=', ProcessingEventType::ReviewCorrected)
            ->get()
            ->each(function (ProcessingEvent $event) use ($cycleId): void {
                $this->assertSame($cycleId, $event->context['cycle_id'] ?? null, $event->type->value);
            });
    }

    public function test_email_phone_ambiguity_can_be_resolved_to_one_duplicate_origin(): void
    {
        Queue::fake();
        $emailOrigin = Lead::factory()->routed()->create([
            'email' => 'origin-email@example.test',
            'normalized_email' => 'origin-email@example.test',
            'phone' => '+12025550121',
            'normalized_phone' => '+12025550121',
            'received_at' => now()->subMinutes(2),
        ]);
        $phoneOrigin = Lead::factory()->routed()->create([
            'email' => 'origin-phone@example.test',
            'normalized_email' => 'origin-phone@example.test',
            'phone' => '+12025550122',
            'normalized_phone' => '+12025550122',
            'received_at' => now()->subMinute(),
        ]);
        $lead = $this->reviewLead([
            'email' => $emailOrigin->email,
            'normalized_email' => $emailOrigin->normalized_email,
            'phone' => $phoneOrigin->phone,
            'normalized_phone' => $phoneOrigin->normalized_phone,
            'service_interest' => 'automation',
            'received_at' => now(),
        ]);

        $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'phone' => $emailOrigin->phone,
        ])->assertRedirect();
        $cycleId = app(LeadExceptionActionService::class)->reprocess($lead);
        app(LeadProcessor::class)->process($lead->id, $cycleId, 1);

        $lead->refresh();
        $this->assertSame(LeadStatus::Duplicate, $lead->status);
        $this->assertSame($emailOrigin->id, $lead->duplicate_of);
        $this->assertNotSame($phoneOrigin->id, $lead->duplicate_of);
    }

    public function test_failed_lead_retry_reaches_routed_and_uses_a_cycle_specific_transmission_key(): void
    {
        Queue::fake();
        $transmitter = new CapturingExceptionActionTransmitter;
        $this->app->instance(LeadTransmitter::class, $transmitter);
        $lead = Lead::factory()->failed()->create([
            'email' => 'retry-success@example.test',
            'normalized_email' => 'retry-success@example.test',
            'phone' => '+12025550131',
            'normalized_phone' => '+12025550131',
            'service_interest' => 'api',
        ]);

        $cycleId = app(LeadExceptionActionService::class)->retry($lead);
        app(LeadProcessor::class)->process($lead->id, $cycleId, 1);

        $this->assertSame(LeadStatus::Routed, $lead->fresh()->status);
        $this->assertSame(["lead:{$lead->id}:cycle:{$cycleId}:transmission"], $transmitter->keys);
        $this->assertSame(1, $lead->fresh()->processing_attempts);
    }

    public function test_new_manual_cycle_gets_three_fresh_attempts_and_can_fail_again(): void
    {
        Queue::fake();
        $this->app->instance(LeadTransmitter::class, new FailingExceptionActionTransmitter);
        $lead = Lead::factory()->failed()->create([
            'email' => 'retry-failure@example.test',
            'normalized_email' => 'retry-failure@example.test',
            'phone' => '+12025550132',
            'normalized_phone' => '+12025550132',
            'service_interest' => 'automation',
        ]);
        ProcessingEvent::factory()->for($lead)->create([
            'type' => ProcessingEventType::ProcessingFailed,
            'attempt' => 3,
            'context' => ['error_code' => 'attempts_exhausted'],
        ]);
        $cycleId = app(LeadExceptionActionService::class)->retry($lead);
        $processor = app(LeadProcessor::class);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $processor->process($lead->id, $cycleId, $attempt);
                $this->fail('The controlled transmission should fail.');
            } catch (LeadTransmissionException) {
                $this->assertSame($attempt, $lead->fresh()->processing_attempts);
            }
        }

        $processor->markFailedAfterExhaustion($lead->id, 3, $cycleId);
        $lead->refresh();
        $this->assertSame(LeadStatus::Failed, $lead->status);
        $this->assertSame(3, $lead->processing_attempts);
        $this->assertSame(2, $lead->processingEvents()->where('type', ProcessingEventType::ProcessingFailed)->count());
        $this->assertSame([1, 2, 3], $lead->processingEvents()
            ->where('type', ProcessingEventType::TransmissionFailed)
            ->pluck('attempt')
            ->all());
    }

    public function test_stale_or_duplicate_jobs_cannot_consume_a_new_cycles_attempts_or_fail_it(): void
    {
        Queue::fake();
        $this->app->instance(LeadTransmitter::class, new FailingExceptionActionTransmitter);
        $lead = Lead::factory()->failed()->create([
            'email' => 'cycle-guard@example.test',
            'normalized_email' => 'cycle-guard@example.test',
            'phone' => '+12025550133',
            'normalized_phone' => '+12025550133',
            'service_interest' => 'automation',
        ]);
        $actions = app(LeadExceptionActionService::class);
        $processor = app(LeadProcessor::class);
        $oldCycle = $actions->retry($lead);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $processor->process($lead->id, $oldCycle, $attempt);
            } catch (LeadTransmissionException) {
                // Expected controlled failure for the old cycle.
            }
        }
        $processor->markFailedAfterExhaustion($lead->id, 3, $oldCycle);
        $newCycle = $actions->retry($lead);
        $eventsBeforeStaleJob = $lead->processingEvents()->count();

        $processor->process($lead->id, $oldCycle, 1);
        $processor->markFailedAfterExhaustion($lead->id, 3, $oldCycle);
        $this->assertSame(LeadStatus::Validating, $lead->fresh()->status);
        $this->assertSame(0, $lead->fresh()->processing_attempts);
        $this->assertSame($eventsBeforeStaleJob, $lead->processingEvents()->count());

        try {
            $processor->process($lead->id, $newCycle, 1);
            $this->fail('The current cycle transmission should fail once.');
        } catch (LeadTransmissionException) {
            // The accepted first attempt is recorded.
        }
        $eventsAfterFirstAttempt = $lead->processingEvents()->count();
        $processor->process($lead->id, $newCycle, 1);

        $this->assertSame(1, $lead->fresh()->processing_attempts);
        $this->assertSame($eventsAfterFirstAttempt, $lead->processingEvents()->count());
        $this->assertNotSame((new ProcessLead($lead->id, $oldCycle))->uniqueId(), (new ProcessLead($lead->id, $newCycle))->uniqueId());

        $oldMiddleware = (new ProcessLead($lead->id, $oldCycle))->middleware()[0];
        $newMiddleware = (new ProcessLead($lead->id, $newCycle))->middleware()[0];
        $newCycleLockWasIndependent = false;
        $oldMiddleware->handle(new HardeningQueueJobProbe, function () use ($newMiddleware, &$newCycleLockWasIndependent): void {
            $newMiddleware->handle(new HardeningQueueJobProbe, function () use (&$newCycleLockWasIndependent): void {
                $newCycleLockWasIndependent = true;
            });
        });
        $this->assertTrue(
            $newCycleLockWasIndependent,
            'A stale cycle lock must not discard the only job for the current cycle.',
        );
    }

    public function test_transition_event_override_is_limited_to_the_exact_manual_transition(): void
    {
        $transition = app(LeadStatusTransition::class);
        $received = Lead::factory()->create(['status' => LeadStatus::Received]);
        $review = $this->reviewLead();

        foreach ([
            [$received, LeadStatus::Validating, ProcessingEventType::ReviewReprocessingRequested],
            [$review, LeadStatus::Validating, ProcessingEventType::ManualRetryRequested],
        ] as [$lead, $to, $override]) {
            try {
                $transition->transition($lead, $to, eventType: $override);
                $this->fail('An incompatible event override must be rejected.');
            } catch (DomainException $exception) {
                $this->assertSame('The event override is not allowed for this status transition.', $exception->getMessage());
            }
        }

        $this->assertSame(LeadStatus::Received, $received->fresh()->status);
        $this->assertSame(LeadStatus::NeedsReview, $review->fresh()->status);
        $this->assertDatabaseCount('processing_events', 0);
    }

    public function test_correction_context_sanitizer_allows_only_whitelisted_field_names(): void
    {
        $lead = $this->reviewLead();
        $event = app(ProcessingEventRecorder::class)->record(
            $lead,
            ProcessingEventType::ReviewCorrected,
            context: [
                'changed_fields' => [
                    'email',
                    'private-value@example.test',
                    'status',
                    'phone',
                    'message-value-must-not-pass',
                ],
            ],
        );

        $this->assertSame(['email', 'phone'], $event->context['changed_fields']);
        $serialized = json_encode($event->context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private-value@example.test', $serialized);
        $this->assertStringNotContainsString('status', $serialized);
        $this->assertStringNotContainsString('message-value', $serialized);
    }

    public function test_lead_save_failure_rolls_back_without_event_or_job_and_returns_a_generic_error(): void
    {
        Queue::fake();
        $lead = $this->reviewLead();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_review_lead_update
            BEFORE UPDATE ON leads
            WHEN NEW.id = OLD.id
            BEGIN
                SELECT RAISE(ABORT, 'private-save-value@example.test');
            END
            SQL);

        try {
            $response = $this->patch(route('leads.review.update', $lead), [
                ...$this->reviewPayload($lead),
                'email' => 'private-updated@example.test',
            ]);
            $response->assertRedirect()->assertSessionHasErrors('action');
            $this->assertSame('review-hardening@example.test', $lead->fresh()->email);
            $this->assertDatabaseCount('processing_events', 0);
            Queue::assertNothingPushed();
            $this->assertStringNotContainsString('private-save-value', serialize(session()->all()));
            $this->assertStringNotContainsString('private-updated', serialize(session()->all()));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_review_lead_update');
        }
    }

    public function test_after_commit_job_insertion_failure_is_generic_but_cannot_roll_back_the_committed_cycle(): void
    {
        $lead = $this->reviewLead();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_exception_job_insert
            BEFORE INSERT ON jobs
            BEGIN
                SELECT RAISE(ABORT, 'private-after-commit-job-failure');
            END
            SQL);

        try {
            $response = $this->post(route('leads.reprocess', $lead));
            $response->assertRedirect()->assertSessionHasErrors('action');
            $lead->refresh();
            $this->assertSame(LeadStatus::Validating, $lead->status);
            $this->assertSame(1, $lead->processingEvents()->where('type', ProcessingEventType::ReviewReprocessingRequested)->count());
            $this->assertDatabaseCount('jobs', 0);
            $this->assertStringNotContainsString('private-after-commit', serialize(session()->all()));
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_exception_job_insert');
        }
    }

    public function test_validation_and_action_failures_do_not_flash_or_log_review_values(): void
    {
        Queue::fake();
        $handler = new TestHandler;
        Log::getLogger()->pushHandler($handler);
        $lead = $this->reviewLead();
        $privateValues = [
            'private-review@example.test',
            '+12025550999',
            'private review message',
            'private corrected first name',
        ];

        $this->from(route('leads.show', $lead))->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'first_name' => '',
            'email' => $privateValues[0],
            'phone' => $privateValues[1],
            'message' => $privateValues[2],
            'last_name' => $privateValues[3],
        ])->assertSessionHasErrors('first_name');

        $session = serialize(session()->all());
        $logs = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);
        foreach ($privateValues as $value) {
            $this->assertStringNotContainsString($value, $session);
            $this->assertStringNotContainsString($value, $logs);
        }
        $this->assertDatabaseCount('processing_events', 0);
    }

    public function test_cached_routes_include_actions_only_in_local_and_testing(): void
    {
        foreach (['local', 'testing'] as $environment) {
            $routeNames = $this->cachedRouteNamesForEnvironment($environment);
            $this->assertContains('leads.review.update', $routeNames);
            $this->assertContains('leads.reprocess', $routeNames);
            $this->assertContains('leads.retry', $routeNames);
        }

        foreach (['production', 'staging'] as $environment) {
            $routeNames = $this->cachedRouteNamesForEnvironment($environment);
            $this->assertNotContains('leads.review.update', $routeNames);
            $this->assertNotContains('leads.reprocess', $routeNames);
            $this->assertNotContains('leads.retry', $routeNames);
        }
    }

    public function test_rate_limit_configuration_is_cached_with_safe_bounds(): void
    {
        $this->assertSame(60, config('leadops.exception_actions.rate_limit_per_minute'));
        $this->assertSame(1, $this->cachedRateLimitFor('-20'));
        $this->assertSame(60, $this->cachedRateLimitFor('500'));
    }

    public function test_sqlite_documents_the_row_lock_proof_limit_while_sequential_guards_remain_tested(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertStringNotContainsString(
            'for update',
            strtolower(Lead::query()->lockForUpdate()->whereKey(1)->toSql()),
            'SQLite omits SELECT FOR UPDATE; a real multiprocess row-lock proof requires a server database.',
        );
    }

    /** @param array<string, mixed> $overrides */
    private function reviewLead(array $overrides = []): Lead
    {
        return Lead::factory()->needsReview()->create([
            'first_name' => 'PrÃ©nom Fictif',
            'last_name' => 'Nom Fictif',
            'company' => 'Entreprise Fictive',
            'email' => 'review-hardening@example.test',
            'phone' => '+12025550111',
            'country' => 'US',
            'company_size' => 12,
            'message' => 'Message fictif pour la revue humaine.',
            'consent' => true,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewPayload(Lead $lead): array
    {
        return [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'company' => $lead->company,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'country' => $lead->country,
            'service_interest' => $lead->service_interest,
            'company_size' => $lead->company_size,
            'message' => $lead->message,
            'consent' => $lead->consent,
        ];
    }

    /** @return list<string|null> */
    private function cachedRouteNamesForEnvironment(string $environment): array
    {
        $suffix = $environment.'-exception-actions-'.bin2hex(random_bytes(4));
        $configCache = "bootstrap/cache/config-{$suffix}.php";
        $routesCache = "bootstrap/cache/routes-{$suffix}.php";
        $processEnvironment = [
            'APP_ENV' => $environment,
            'APP_CONFIG_CACHE' => $configCache,
            'APP_ROUTES_CACHE' => $routesCache,
        ];

        try {
            foreach ([['config:cache'], ['route:cache']] as $command) {
                (new Process([PHP_BINARY, 'artisan', ...$command], base_path(), $processEnvironment))->mustRun();
            }

            $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--json'], base_path(), $processEnvironment);
            $process->mustRun();

            return array_column(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'name');
        } finally {
            @unlink(base_path($configCache));
            @unlink(base_path($routesCache));
        }
    }

    private function cachedRateLimitFor(string $configuredValue): int
    {
        $configCache = 'bootstrap/cache/config-exception-rate-'.bin2hex(random_bytes(4)).'.php';
        $process = new Process([PHP_BINARY, 'artisan', 'config:cache'], base_path(), [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $configCache,
            'LEADOPS_EXCEPTION_ACTIONS_RATE_LIMIT' => $configuredValue,
        ]);

        try {
            $process->mustRun();
            $configuration = require base_path($configCache);

            return $configuration['leadops']['exception_actions']['rate_limit_per_minute'];
        } finally {
            @unlink(base_path($configCache));
        }
    }
}

final class CapturingExceptionActionTransmitter implements LeadTransmitter
{
    /** @var list<string> */
    public array $keys = [];

    public function transmit(Lead $lead, string $idempotencyKey): TransmissionResult
    {
        $this->keys[] = $idempotencyKey;

        return TransmissionResult::succeeded('simulated-cycle-specific-reference');
    }
}

final class FailingExceptionActionTransmitter implements LeadTransmitter
{
    public function transmit(Lead $lead, string $idempotencyKey): TransmissionResult
    {
        return TransmissionResult::failed('controlled_unavailable');
    }
}

final class HardeningQueueJobProbe
{
    public function release(int $delay): void
    {
        // WithoutOverlapping::dontRelease() must never call this method.
    }
}
