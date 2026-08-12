<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Jobs\ProcessLead;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

    public function test_store_route_has_csrf_protection_and_is_rate_limited(): void
    {
        $route = app('router')->getRoutes()->getByName('leads.store');
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('throttle:lead-form', $route->gatherMiddleware());
        $this->get(route('leads.create'))->assertSee('name="_token"', false);

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
        $lead = Lead::factory()->create([
            'first_name' => '<script>alert("name")</script>',
            'message' => '<img src=x onerror=alert("message")>',
        ]);
        ProcessingEvent::factory()->for($lead)->create([
            'context' => ['rule_codes' => ['<script>alert("event")</script>']],
        ]);

        $response = $this->get(route('leads.show', $lead));

        $response->assertOk()
            ->assertSee('&lt;script&gt;alert(&quot;name&quot;)&lt;/script&gt;', false)
            ->assertSee('&lt;img src=x onerror=alert(&quot;message&quot;)&gt;', false)
            ->assertSee('&lt;script&gt;alert(&quot;event&quot;)&lt;/script&gt;', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('<img src=x', false);
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
