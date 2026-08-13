<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use App\Services\Leads\LeadExceptionActionService;
use App\Services\Leads\LeadProcessor;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ExceptionLeadActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('leadops.exception_actions.rate_limit_per_minute', 60);
    }

    public function test_review_correction_updates_only_allowed_fields_without_transition_or_job(): void
    {
        Queue::fake();
        $lead = $this->reviewLead();
        $protectedFields = [
            'source', 'external_reference', 'received_at', 'status', 'score', 'classification',
            'assigned_to', 'duplicate_of', 'processing_attempts', 'processed_at',
            'normalized_email', 'normalized_phone',
        ];
        $original = array_intersect_key($lead->getRawOriginal(), array_flip($protectedFields));

        $response = $this->patch(route('leads.review.update', $lead), [
            ...$this->reviewPayload($lead),
            'first_name' => 'Prénom Corrigé',
            'company' => 'Société Fictive Corrigée',
            'email' => 'correction@example.test',
            'unexpected_parameter' => 'ignored-value',
        ]);

        $response->assertRedirect(route('leads.show', $lead))
            ->assertSessionHas('status', 'Les informations du lead ont été enregistrées.');
        $lead->refresh();
        $this->assertSame(LeadStatus::NeedsReview, $lead->status);
        $this->assertSame('Prénom Corrigé', $lead->first_name);
        $this->assertSame('Société Fictive Corrigée', $lead->company);
        $this->assertSame('correction@example.test', $lead->email);
        $this->assertSame($original, array_intersect_key($lead->getRawOriginal(), array_flip($protectedFields)));
        Queue::assertNothingPushed();

        $event = $lead->processingEvents()->where('type', ProcessingEventType::ReviewCorrected)->sole();
        $this->assertSame(['changed_fields'], array_keys($event->context));
        $this->assertSame(['first_name', 'company', 'email'], $event->context['changed_fields']);
        $serialized = json_encode($event->context, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Prénom Corrigé', $serialized);
        $this->assertStringNotContainsString('correction@example.test', $serialized);
    }

    public function test_review_correction_rejects_immutable_fields_and_non_review_statuses(): void
    {
        Queue::fake();
        $review = $this->reviewLead();

        $this->patch(route('leads.review.update', $review), [
            ...$this->reviewPayload($review),
            'status' => LeadStatus::Routed->value,
            'score' => 100,
            'normalized_email' => 'protected@example.test',
        ])->assertSessionHasErrors(['status', 'score', 'normalized_email']);

        $this->assertSame(LeadStatus::NeedsReview, $review->fresh()->status);
        $this->assertDatabaseMissing('processing_events', [
            'lead_id' => $review->id,
            'type' => ProcessingEventType::ReviewCorrected->value,
        ]);

        foreach ([
            LeadStatus::Received,
            LeadStatus::Validating,
            LeadStatus::Invalid,
            LeadStatus::Duplicate,
            LeadStatus::Qualified,
            LeadStatus::Routed,
            LeadStatus::Failed,
        ] as $status) {
            $lead = Lead::factory()->create(['status' => $status]);
            $this->patch(route('leads.review.update', $lead), $this->reviewPayload($lead))
                ->assertSessionHasErrors('action');
            $this->assertSame($status, $lead->fresh()->status);
        }

        Queue::assertNothingPushed();
    }

    public function test_identical_review_submission_does_not_duplicate_the_correction_event(): void
    {
        Queue::fake();
        $lead = $this->reviewLead();
        $payload = [...$this->reviewPayload($lead), 'first_name' => 'Valeur Corrigée'];

        $this->patch(route('leads.review.update', $lead), $payload)->assertRedirect();
        $this->patch(route('leads.review.update', $lead), $payload)->assertRedirect();

        $this->assertSame(1, $lead->processingEvents()->where('type', ProcessingEventType::ReviewCorrected)->count());
        Queue::assertNothingPushed();
    }

    public function test_reprocess_opens_one_cycle_clears_derived_results_and_dispatches_once_after_commit(): void
    {
        $origin = Lead::factory()->routed()->create();
        $lead = $this->reviewLead([
            'score' => 77,
            'classification' => LeadClassification::Warm,
            'assigned_to' => 'Yassine',
            'duplicate_of' => $origin->id,
            'processing_attempts' => 2,
            'processed_at' => now(),
        ]);
        ProcessingEvent::factory()->for($lead)->create(['type' => ProcessingEventType::ReviewRequested]);

        DB::beginTransaction();
        $cycleId = app(LeadExceptionActionService::class)->reprocess($lead);
        $this->assertDatabaseCount('jobs', 0);
        DB::commit();

        $lead->refresh();
        $this->assertSame(LeadStatus::Validating, $lead->status);
        $this->assertNull($lead->score);
        $this->assertNull($lead->normalized_email);
        $this->assertNull($lead->normalized_phone);
        $this->assertNull($lead->classification);
        $this->assertNull($lead->assigned_to);
        $this->assertNull($lead->duplicate_of);
        $this->assertSame(0, $lead->processing_attempts);
        $this->assertNull($lead->processed_at);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(2, $lead->processingEvents()->count());
        $event = $lead->processingEvents()
            ->where('type', ProcessingEventType::ReviewReprocessingRequested)
            ->sole();
        $this->assertSame(ProcessingEventType::ReviewReprocessingRequested, $event->type);
        $this->assertSame(LeadStatus::NeedsReview, $event->from_status);
        $this->assertSame(LeadStatus::Validating, $event->to_status);
        $this->assertSame(['cycle_id' => $cycleId], $event->context);
        $this->assertDatabaseMissing('processing_events', [
            'lead_id' => $lead->id,
            'type' => ProcessingEventType::ProcessingStarted->value,
        ]);

        $this->post(route('leads.reprocess', $lead))->assertSessionHasErrors('action');
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(1, $lead->processingEvents()->where('type', ProcessingEventType::ReviewReprocessingRequested)->count());
    }

    public function test_manual_retry_opens_one_fresh_three_attempt_cycle_without_erasing_history(): void
    {
        Queue::fake();
        $lead = Lead::factory()->failed()->create([
            'score' => 80,
            'classification' => LeadClassification::Hot,
            'assigned_to' => 'Sarah',
        ]);
        ProcessingEvent::factory()->for($lead)->create([
            'type' => ProcessingEventType::ProcessingFailed,
            'attempt' => 3,
            'context' => ['error_code' => 'attempts_exhausted'],
        ]);

        $this->post(route('leads.retry', $lead))->assertRedirect(route('leads.show', $lead));
        $lead->refresh();

        $this->assertSame(LeadStatus::Validating, $lead->status);
        $this->assertSame(0, $lead->processing_attempts);
        $this->assertNull($lead->normalized_email);
        $this->assertNull($lead->normalized_phone);
        $this->assertNull($lead->score);
        $this->assertNull($lead->classification);
        $this->assertNull($lead->assigned_to);
        $this->assertSame(1, $lead->processingEvents()->where('type', ProcessingEventType::ProcessingFailed)->count());
        $action = $lead->processingEvents()->where('type', ProcessingEventType::ManualRetryRequested)->sole();
        $this->assertArrayHasKey('cycle_id', $action->context);
        $this->assertDatabaseMissing('processing_events', [
            'lead_id' => $lead->id,
            'type' => ProcessingEventType::ProcessingStarted->value,
        ]);
        Queue::assertPushed(ProcessLead::class, function (ProcessLead $job) use ($lead, $action): bool {
            return $job->leadId === $lead->id
                && $job->cycleId === $action->context['cycle_id']
                && $job->tries === 3
                && $job->afterCommit;
        });

        $this->post(route('leads.retry', $lead))->assertSessionHasErrors('action');
        Queue::assertPushed(ProcessLead::class, 1);
        $this->assertSame(1, $lead->processingEvents()->where('type', ProcessingEventType::ManualRetryRequested)->count());
    }

    public function test_reprocess_and_retry_refuse_every_incompatible_or_terminal_status(): void
    {
        Queue::fake();

        foreach (LeadStatus::cases() as $status) {
            if ($status !== LeadStatus::NeedsReview) {
                $lead = Lead::factory()->create(['status' => $status]);
                $this->post(route('leads.reprocess', $lead))->assertSessionHasErrors('action');
                $this->assertSame($status, $lead->fresh()->status);
            }

            if ($status !== LeadStatus::Failed) {
                $lead = Lead::factory()->create(['status' => $status]);
                $this->post(route('leads.retry', $lead))->assertSessionHasErrors('action');
                $this->assertSame($status, $lead->fresh()->status);
            }
        }

        Queue::assertNothingPushed();
    }

    public function test_action_event_failure_rolls_back_status_event_and_job(): void
    {
        $lead = $this->reviewLead(['processing_attempts' => 2]);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_reprocessing_action
            BEFORE INSERT ON processing_events
            WHEN NEW.type = 'review.reprocessing_requested'
            BEGIN
                SELECT RAISE(ABORT, 'simulated action failure');
            END
            SQL);

        try {
            app(LeadExceptionActionService::class)->reprocess($lead);
            $this->fail('The action event failure must roll back the mutation.');
        } catch (QueryException) {
            $lead->refresh();
            $this->assertSame(LeadStatus::NeedsReview, $lead->status);
            $this->assertSame(2, $lead->processing_attempts);
            $this->assertDatabaseCount('processing_events', 0);
            $this->assertDatabaseCount('jobs', 0);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_reprocessing_action');
        }
    }

    public function test_outer_rollback_cancels_the_cycle_event_transition_and_after_commit_job(): void
    {
        $lead = $this->reviewLead(['processing_attempts' => 2]);

        DB::beginTransaction();
        app(LeadExceptionActionService::class)->reprocess($lead);
        $this->assertDatabaseCount('jobs', 0);
        DB::rollBack();

        $lead->refresh();
        $this->assertSame(LeadStatus::NeedsReview, $lead->status);
        $this->assertSame(2, $lead->processing_attempts);
        $this->assertDatabaseCount('processing_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_correction_journal_failure_rolls_back_and_returns_no_sensitive_error_details(): void
    {
        $lead = $this->reviewLead();
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_review_correction
            BEFORE INSERT ON processing_events
            WHEN NEW.type = 'review.corrected'
            BEGIN
                SELECT RAISE(ABORT, 'simulated correction failure');
            END
            SQL);

        $privateEmail = 'private-correction@example.test';
        $privateMessage = 'private corrected message must never appear in an error';

        try {
            $response = $this->patch(route('leads.review.update', $lead), [
                ...$this->reviewPayload($lead),
                'email' => $privateEmail,
                'message' => $privateMessage,
            ]);

            $response->assertRedirect()->assertSessionHasErrors('action');
            $this->assertStringNotContainsString($privateEmail, $response->getContent());
            $this->assertStringNotContainsString($privateMessage, $response->getContent());
            $this->assertSame('review-action@example.test', $lead->fresh()->email);
            $this->assertDatabaseCount('processing_events', 0);
            $this->assertDatabaseCount('jobs', 0);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_review_correction');
        }
    }

    public function test_job_execution_records_the_real_cycle_start_then_recalculates_the_pipeline(): void
    {
        Queue::fake();
        $lead = $this->reviewLead([
            'service_interest' => 'automation',
            'normalized_email' => null,
            'normalized_phone' => null,
        ]);
        $cycleId = app(LeadExceptionActionService::class)->reprocess($lead);

        (new ProcessLead($lead->id, $cycleId))
            ->handle(app(LeadProcessor::class));
        $lead->refresh();

        $this->assertSame(LeadStatus::Routed, $lead->status);
        $this->assertNotNull($lead->score);
        $this->assertNotNull($lead->classification);
        $this->assertNotNull($lead->assigned_to);
        $this->assertSame(1, $lead->processing_attempts);
        $types = $lead->processingEvents()->get()->map(fn ($event) => $event->type->value)->all();
        $this->assertSame(ProcessingEventType::ReviewReprocessingRequested->value, $types[0]);
        $this->assertSame(ProcessingEventType::ProcessingStarted->value, $types[1]);
        $started = $lead->processingEvents()->where('type', ProcessingEventType::ProcessingStarted)->sole();
        $this->assertSame(['cycle_id' => $cycleId], $started->context);
    }

    public function test_reprocessed_lead_can_return_to_needs_review_when_problem_remains(): void
    {
        Queue::fake();
        $lead = $this->reviewLead(['service_interest' => 'still_unknown']);
        $cycleId = app(LeadExceptionActionService::class)->reprocess($lead);

        app(LeadProcessor::class)->process($lead->id, $cycleId);

        $lead->refresh();
        $this->assertSame(LeadStatus::NeedsReview, $lead->status);
        $this->assertSame(1, $lead->processing_attempts);
        $this->assertSame(1, $lead->processingEvents()->where('type', ProcessingEventType::ProcessingStarted)->count());
        $this->assertSame(1, $lead->processingEvents()->where('type', ProcessingEventType::ReviewRequested)->count());
    }

    public function test_routes_use_web_csrf_environment_guard_and_pseudonymous_rate_limit(): void
    {
        foreach (['leads.review.update', 'leads.reprocess', 'leads.retry'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $this->assertContains('web', $route->gatherMiddleware());
            $this->assertContains('throttle:exception-actions', $route->gatherMiddleware());
        }

        $clientIp = '192.0.2.90';
        $request = Request::create('/leads/1/retry', 'POST', server: ['REMOTE_ADDR' => $clientIp]);
        $limit = RateLimiter::limiter('exception-actions')($request);
        $this->assertSame((int) config('leadops.exception_actions.rate_limit_per_minute'), $limit->maxAttempts);
        $this->assertStringNotContainsString($clientIp, $limit->key);
        $this->assertMatchesRegularExpression('/^exception-actions:[a-f0-9]{64}$/', $limit->key);

        $lead = Lead::factory()->failed()->create();
        $this->app->detectEnvironment(static fn (): string => 'local');
        $this->withoutExceptionHandling();
        try {
            $this->post('/leads/'.$lead->id.'/retry');
            $this->fail('A retry without a CSRF token must be rejected.');
        } catch (TokenMismatchException) {
            $this->assertSame(LeadStatus::Failed, $lead->fresh()->status);
        }
    }

    public function test_actions_and_forms_are_unavailable_outside_local_and_testing(): void
    {
        $review = $this->reviewLead();
        $failed = Lead::factory()->failed()->create();
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get(route('leads.show', $review))
            ->assertOk()
            ->assertDontSee('Enregistrer les corrections')
            ->assertDontSee('Retraiter le lead');
        $this->get(route('leads.show', $failed))
            ->assertOk()
            ->assertDontSee('Relancer le traitement');
        $this->patch('/leads/'.$review->id.'/review', $this->reviewPayload($review))->assertNotFound();
        $this->post('/leads/'.$review->id.'/reprocess')->assertNotFound();
        $this->post('/leads/'.$failed->id.'/retry')->assertNotFound();
    }

    public function test_detail_shows_only_the_action_matching_the_exception_status_and_accessible_errors(): void
    {
        $review = $this->reviewLead();
        $failed = Lead::factory()->failed()->create();
        $terminal = Lead::factory()->routed()->create();

        $this->get(route('leads.show', $review))
            ->assertOk()
            ->assertSee('Enregistrer les corrections')
            ->assertSee('Retraiter le lead')
            ->assertDontSee('Relancer le traitement');
        $this->get(route('leads.show', $failed))
            ->assertOk()
            ->assertSee('Relancer le traitement')
            ->assertDontSee('Enregistrer les corrections');
        $this->get(route('leads.show', $terminal))
            ->assertOk()
            ->assertDontSee('Enregistrer les corrections')
            ->assertDontSee('Retraiter le lead')
            ->assertDontSee('Relancer le traitement');

        $response = $this->followingRedirects()->from(route('leads.show', $review))->patch(route('leads.review.update', $review), [
            ...$this->reviewPayload($review),
            'first_name' => '',
        ]);
        $response
            ->assertSee('role="alert"', false)
            ->assertSee('href="#first_name"', false)
            ->assertSee('aria-invalid="true" aria-describedby="first_name-error"', false);
    }

    /** @param array<string, mixed> $overrides */
    private function reviewLead(array $overrides = []): Lead
    {
        return Lead::factory()->needsReview()->create([
            'first_name' => 'Prénom Fictif',
            'last_name' => 'Nom Fictif',
            'company' => 'Entreprise Fictive',
            'email' => 'review-action@example.test',
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
}
