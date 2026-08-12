<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class LeadFormAndDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_form_display_generates_a_fresh_server_uuid_without_prefilled_personal_data(): void
    {
        $first = $this->get(route('leads.create'));
        $second = $this->get(route('leads.create'));

        $first->assertOk()->assertSee('Créer un lead fictif');
        $second->assertOk();

        $firstReference = $this->hiddenReference($first->getContent());
        $secondReference = $this->hiddenReference($second->getContent());

        $this->assertTrue(Str::isUuid($firstReference));
        $this->assertTrue(Str::isUuid($secondReference));
        $this->assertNotSame($firstReference, $secondReference);
        $first->assertDontSee('value="Aline', false);
        $first->assertDontSee('name="source"', false);
    }

    public function test_validation_error_redisplays_the_exact_same_valid_uuid(): void
    {
        $reference = (string) Str::uuid();

        $response = $this->from(route('leads.create'))->post(route('leads.store'), [
            ...$this->validPayload($reference),
            'first_name' => '',
        ]);

        $response->assertRedirect(route('leads.create'))
            ->assertSessionHasErrors('first_name')
            ->assertSessionHasInput('external_reference', $reference);

        $redisplayed = $this->get(route('leads.create'));
        $redisplayed->assertOk();
        $this->assertSame($reference, $this->hiddenReference($redisplayed->getContent()));
    }

    public function test_form_creation_forces_source_records_safe_receipt_and_dispatches_after_commit(): void
    {
        Queue::fake();
        $reference = (string) Str::uuid();

        $response = $this->post(route('leads.store'), $this->validPayload($reference));

        $lead = Lead::query()->sole();
        $response->assertRedirect(route('leads.show', $lead))
            ->assertSessionHas('status', 'Le lead a bien été reçu et son traitement va commencer.');
        $this->assertSame('form', $lead->source->value);
        $this->assertSame(LeadStatus::Received, $lead->status);
        $this->assertSame(0, $lead->processing_attempts);

        $event = $lead->processingEvents()->sole();
        $this->assertSame(ProcessingEventType::LeadReceived, $event->type);
        $this->assertSame(['reference_fingerprint' => substr(hash('sha256', 'form|'.$reference), 0, 16)], $event->context);
        $this->assertStringNotContainsString($lead->email, json_encode($event->context, JSON_THROW_ON_ERROR));

        Queue::assertPushed(ProcessLead::class, function (ProcessLead $job) use ($lead): bool {
            return $job->leadId === $lead->id && $job->afterCommit;
        });
    }

    public function test_ingestion_ignores_protected_attributes_from_any_caller(): void
    {
        Queue::fake();
        $reference = (string) Str::uuid();
        $attributes = $this->validPayload($reference);
        unset($attributes['external_reference']);

        $result = app(LeadIngestionService::class)->ingest(
            LeadSource::Form,
            $reference,
            [
                ...$attributes,
                'source' => 'webhook',
                'external_reference' => (string) Str::uuid(),
                'status' => LeadStatus::Failed->value,
                'processing_attempts' => 3,
            ],
        );

        $this->assertTrue($result->created);
        $this->assertSame(LeadSource::Form, $result->lead->source);
        $this->assertSame($reference, $result->lead->external_reference);
        $this->assertSame(LeadStatus::Received, $result->lead->status);
        $this->assertSame(0, $result->lead->processing_attempts);
    }

    public function test_database_job_is_inserted_once_only_after_the_outer_commit(): void
    {
        $reference = (string) Str::uuid();
        $attributes = $this->validPayload($reference);
        unset($attributes['external_reference']);

        DB::beginTransaction();

        $first = app(LeadIngestionService::class)->ingest(
            LeadSource::Form,
            $reference,
            $attributes,
        );

        $this->assertTrue($first->created);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('processing_events', 1);
        $this->assertDatabaseCount('jobs', 0);

        DB::commit();

        $this->assertDatabaseCount('jobs', 1);

        $repeated = app(LeadIngestionService::class)->ingest(
            LeadSource::Form,
            $reference,
            [...$attributes, 'first_name' => 'Ne doit pas remplacer'],
        );

        $this->assertFalse($repeated->created);
        $this->assertTrue($first->lead->is($repeated->lead));
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('processing_events', 1);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_lead_event_and_job_are_rolled_back_together_when_receipt_recording_fails(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER fail_lead_received_event
            BEFORE INSERT ON processing_events
            WHEN NEW.type = 'lead.received'
            BEGIN
                SELECT RAISE(ABORT, 'simulated receipt event failure');
            END
            SQL);

        $reference = (string) Str::uuid();
        $attributes = $this->validPayload($reference);
        unset($attributes['external_reference']);

        try {
            app(LeadIngestionService::class)->ingest(
                LeadSource::Form,
                $reference,
                $attributes,
            );
            $this->fail('The receipt event failure must roll back the entire ingestion.');
        } catch (QueryException) {
            $this->assertDatabaseCount('leads', 0);
            $this->assertDatabaseCount('processing_events', 0);
            $this->assertDatabaseCount('jobs', 0);
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_lead_received_event');
        }
    }

    public function test_repeated_submission_returns_existing_lead_without_a_second_event_or_job(): void
    {
        Queue::fake();
        $reference = (string) Str::uuid();
        $payload = $this->validPayload($reference);

        $this->post(route('leads.store'), $payload)->assertRedirect();
        $lead = Lead::query()->sole();
        $repeated = $this->post(route('leads.store'), [
            ...$payload,
            'first_name' => 'Valeur qui ne doit pas remplacer la première',
        ]);

        $repeated->assertRedirect(route('leads.show', $lead))
            ->assertSessionHas('status', 'Ce lead avait déjà été reçu. Aucun nouveau traitement n’a été lancé.');
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('processing_events', 1);
        $this->assertSame('Prénom Fictif', $lead->fresh()->first_name);
        Queue::assertPushed(ProcessLead::class, 1);
    }

    public function test_validation_rejects_invalid_reference_missing_contact_consent_and_client_source_while_retaining_valid_fields(): void
    {
        Queue::fake();

        $response = $this->from(route('leads.create'))->post(route('leads.store'), [
            ...$this->validPayload('not-a-uuid'),
            'source' => 'webhook',
            'email' => null,
            'phone' => null,
            'consent' => null,
        ]);

        $response->assertRedirect(route('leads.create'))
            ->assertSessionHasErrors(['source', 'external_reference', 'email', 'phone', 'consent'])
            ->assertSessionHasInput('first_name', 'Prénom Fictif')
            ->assertSessionHasInput('external_reference', 'not-a-uuid');
        $this->assertDatabaseCount('leads', 0);
        Queue::assertNothingPushed();
    }

    public function test_csrf_middleware_rejects_a_missing_token_and_accepts_the_session_token(): void
    {
        Queue::fake();
        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->withoutExceptionHandling();

        try {
            $this->post('/leads', $this->validPayload((string) Str::uuid()));
            $this->fail('A POST without a CSRF token must be rejected by the active middleware.');
        } catch (TokenMismatchException) {
            $this->assertDatabaseCount('leads', 0);
        }

        $token = 'csrf-test-token-without-personal-data';
        $this->withSession(['_token' => $token])
            ->post('/leads', [
                ...$this->validPayload((string) Str::uuid()),
                '_token' => $token,
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('leads', 1);
    }

    public function test_store_route_is_rate_limited_with_a_pseudonymous_key(): void
    {
        $route = app('router')->getRoutes()->getByName('leads.store');
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:lead-form', $route->gatherMiddleware());

        $clientIp = '192.0.2.45';
        $personalEmail = 'private-rate-key@example.test';
        $request = Request::create('/leads', 'POST', ['email' => $personalEmail], server: [
            'REMOTE_ADDR' => $clientIp,
        ]);
        $limit = RateLimiter::limiter('lead-form')($request);

        $this->assertStringNotContainsString($clientIp, $limit->key);
        $this->assertStringNotContainsString($personalEmail, $limit->key);
        $this->assertMatchesRegularExpression('/^lead-form:[a-f0-9]{64}$/', $limit->key);

        Queue::fake();
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('leads.store'), $this->validPayload((string) Str::uuid()))->assertRedirect();
        }

        $this->post(route('leads.store'), $this->validPayload((string) Str::uuid()))->assertTooManyRequests();
        $this->assertDatabaseCount('leads', 5);
    }

    public function test_form_mutation_is_unavailable_outside_local_and_testing(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/leads/create')->assertNotFound();
        $this->post('/leads', $this->validPayload((string) Str::uuid()))->assertNotFound();
        $this->assertDatabaseCount('leads', 0);
    }

    public function test_detail_displays_status_business_data_pending_state_and_chronological_timeline(): void
    {
        $lead = Lead::factory()->create([
            'first_name' => 'Ada-Fictive',
            'last_name' => 'Exemple',
            'company' => 'Atelier Imaginaire',
            'status' => LeadStatus::Validating,
            'score' => null,
            'classification' => null,
            'assigned_to' => null,
        ]);
        ProcessingEvent::factory()->for($lead)->create([
            'type' => ProcessingEventType::LeadReceived,
            'created_at' => '2026-01-15 09:00:00',
        ]);
        ProcessingEvent::factory()->for($lead)->create([
            'type' => ProcessingEventType::ProcessingStarted,
            'from_status' => LeadStatus::Received,
            'to_status' => LeadStatus::Validating,
            'created_at' => '2026-01-15 09:01:00',
        ]);

        $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Ada-Fictive')
            ->assertSee('Atelier Imaginaire')
            ->assertSee('Validation en cours')
            ->assertSee('Traitement en attente ou en cours')
            ->assertSee('En attente')
            ->assertSeeInOrder(['Lead reçu', 'Traitement démarré']);
    }

    public function test_timeline_uses_event_id_as_a_stable_tiebreaker(): void
    {
        $lead = Lead::factory()->create();
        $createdAt = '2026-01-15 09:00:00';
        $first = ProcessingEvent::factory()->for($lead)->create([
            'type' => ProcessingEventType::LeadReceived,
            'created_at' => $createdAt,
        ]);
        $second = ProcessingEvent::factory()->for($lead)->create([
            'type' => ProcessingEventType::ProcessingStarted,
            'created_at' => $createdAt,
        ]);

        $this->assertSame([$first->id, $second->id], $lead->processingEvents->pluck('id')->all());
        $this->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSeeInOrder(['Lead reçu', 'Traitement démarré']);
    }

    public function test_detail_renders_a_clear_label_for_every_pipeline_status(): void
    {
        $labels = [
            LeadStatus::Received->value => 'Reçu',
            LeadStatus::Validating->value => 'Validation en cours',
            LeadStatus::Invalid->value => 'Invalide',
            LeadStatus::Duplicate->value => 'Doublon',
            LeadStatus::Qualified->value => 'Qualifié',
            LeadStatus::NeedsReview->value => 'À examiner',
            LeadStatus::Routed->value => 'Routé',
            LeadStatus::Failed->value => 'Échec',
        ];

        foreach ($labels as $status => $label) {
            $lead = Lead::factory()->create(['status' => $status]);

            $this->get(route('leads.show', $lead))
                ->assertOk()
                ->assertSee($label);
        }
    }

    public function test_detail_displays_classification_assignment_and_safe_duplicate_reference(): void
    {
        $origin = Lead::factory()->routed()->create([
            'company' => 'Origine Confidentielle Fictive',
            'message' => 'Contenu origine confidentiel fictif.',
        ]);
        $duplicate = Lead::factory()->duplicateOf($origin)->create([
            'email' => 'doublon-distinct@example.test',
            'phone' => '+12025550999',
            'classification' => LeadClassification::Warm,
            'score' => 62,
            'assigned_to' => 'Yassine',
        ]);

        $this->get(route('leads.show', $duplicate))
            ->assertOk()
            ->assertSee('Doublon détecté')
            ->assertSee('WARM')
            ->assertSee('62')
            ->assertSee('Yassine')
            ->assertSee('#'.$origin->id)
            ->assertDontSee($origin->email)
            ->assertDontSee($origin->company)
            ->assertDontSee($origin->message);
    }

    public function test_detail_eager_loads_relations_without_n_plus_one_queries(): void
    {
        $origin = Lead::factory()->routed()->create();
        $lead = Lead::factory()->duplicateOf($origin)->create();
        ProcessingEvent::factory()->count(12)->for($lead)->create();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('leads.show', $lead))->assertOk();
        $selects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'));

        $this->assertLessThanOrEqual(3, $selects->count(), $selects->pluck('query')->implode("\n"));
    }

    public function test_user_content_is_escaped_on_detail_and_in_timeline(): void
    {
        $userValues = [
            'first_name' => '<script>alert("first")</script>',
            'last_name' => '<script>alert("last")</script>',
            'company' => '<img src=x onerror=alert("company")>',
            'email' => '<svg onload=alert("email")>',
            'phone' => '<script>alert("phone")</script>',
            'country' => '<b>',
            'service_interest' => '<script>alert("service")</script>',
            'message' => '<img src=x onerror=alert("message")>',
        ];
        $lead = Lead::factory()->create([
            ...$userValues,
        ]);
        $eventValue = '<script>alert("event")</script>';
        ProcessingEvent::factory()->for($lead)->create([
            'context' => ['rule_codes' => [$eventValue]],
        ]);

        $response = $this->get(route('leads.show', $lead));

        $response->assertOk();

        foreach ([...array_values($userValues), $eventValue] as $value) {
            $response->assertSee(e(str_replace('_', ' ', $value)), false)
                ->assertDontSee($value, false);
        }
    }

    public function test_validation_errors_and_flash_messages_have_accessible_semantics(): void
    {
        $reference = (string) Str::uuid();
        $this->from(route('leads.create'))->post(route('leads.store'), [
            ...$this->validPayload($reference),
            'first_name' => '',
            'consent' => null,
        ]);

        $form = $this->get(route('leads.create'));
        $form->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('href="#first_name"', false)
            ->assertSee('aria-invalid="true" aria-describedby="first_name-error"', false)
            ->assertSee('href="#consent"', false)
            ->assertSee('aria-invalid="true" aria-describedby="consent-error"', false);

        $lead = Lead::factory()->create();
        $this->withSession(['status' => 'Message générique accessible.'])
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('role="status" aria-atomic="true"', false)
            ->assertSee('Message générique accessible.');
    }

    /** @return array<string, mixed> */
    private function validPayload(string $reference): array
    {
        return [
            'external_reference' => $reference,
            'first_name' => 'Prénom Fictif',
            'last_name' => 'Nom Fictif',
            'company' => 'Atelier Imaginaire',
            'email' => 'lead-form@example.test',
            'phone' => '+12025550123',
            'country' => 'FR',
            'service_interest' => 'automation',
            'company_size' => 12,
            'message' => 'Message entièrement fictif pour valider le formulaire LeadOps.',
            'consent' => '1',
        ];
    }

    private function hiddenReference(string $html): string
    {
        preg_match('/name="external_reference" value="([^"]+)"/', $html, $matches);

        $this->assertArrayHasKey(1, $matches);

        return $matches[1];
    }
}
