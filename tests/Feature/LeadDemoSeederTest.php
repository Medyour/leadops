<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\LeadClassifier;
use App\Domain\LeadProcessing\LeadRouter;
use App\Domain\LeadProcessing\LeadScorer;
use App\Models\Lead;
use Database\Seeders\LeadDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LeadDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCES = [
        'demo-hot-001',
        'demo-warm-001',
        'demo-low-001',
        'demo-invalid-001',
        'demo-invalid-phone-001',
        'demo-duplicate-001',
        'demo-review-001',
        'demo-failed-001',
    ];

    private const ALLOWED_CONTEXT_KEYS = [
        'assigned_to', 'candidate_ids', 'changed_fields', 'classification', 'cycle_id',
        'error_code', 'idempotency_key', 'reason_codes', 'reference_fingerprint',
        'retry_delay', 'rule_codes', 'score', 'service',
    ];

    public function test_demo_manifest_relations_timelines_and_pages_are_complete(): void
    {
        Queue::fake();
        $this->seed(LeadDemoSeeder::class);

        $leads = Lead::query()->whereIn('external_reference', self::REFERENCES)
            ->with('processingEvents')->get()->keyBy('external_reference');

        $this->assertDatabaseCount('leads', 8);
        $this->assertSame(self::REFERENCES, $leads->keys()->all());
        $this->assertSame([
            ['demo-hot-001', LeadSource::Form, LeadStatus::Routed, LeadClassification::Hot, 90, 'Sarah'],
            ['demo-warm-001', LeadSource::Csv, LeadStatus::Routed, LeadClassification::Warm, 70, 'Yassine'],
            ['demo-low-001', LeadSource::Webhook, LeadStatus::Routed, LeadClassification::Low, 40, 'Emma'],
            ['demo-invalid-001', LeadSource::Form, LeadStatus::Invalid, null, null, null],
            ['demo-invalid-phone-001', LeadSource::Csv, LeadStatus::Invalid, null, null, null],
            ['demo-duplicate-001', LeadSource::Webhook, LeadStatus::Duplicate, null, null, null],
            ['demo-review-001', LeadSource::Form, LeadStatus::NeedsReview, null, null, null],
            ['demo-failed-001', LeadSource::Webhook, LeadStatus::Failed, LeadClassification::Hot, 90, 'Sarah'],
        ], $leads->map(fn (Lead $lead): array => [
            $lead->external_reference, $lead->source, $lead->status, $lead->classification,
            $lead->score, $lead->assigned_to,
        ])->values()->all());

        $this->assertSame($leads['demo-hot-001']->id, $leads['demo-duplicate-001']->duplicate_of);
        $review = $leads['demo-review-001']->processingEvents->last();
        $this->assertSame(ProcessingEventType::ReviewRequested, $review->type);
        $this->assertSame(['duplicate.ambiguous'], $review->context['reason_codes']);
        $this->assertSame([$leads['demo-hot-001']->id, $leads['demo-warm-001']->id], $review->context['candidate_ids']);

        foreach ($leads as $lead) {
            $this->assertStringStartsWith('demo-', $lead->external_reference);
            $this->assertTrue($lead->email === null
                || str_ends_with($lead->email, '@example.test')
                || $lead->email === 'adresse-invalide');
            if ($lead->phone !== null) {
                preg_match('/202-555-(\d{4})/', $lead->phone, $matches);
                $this->assertNotEmpty($matches);
                $this->assertGreaterThanOrEqual(100, (int) $matches[1]);
                $this->assertLessThanOrEqual(199, (int) $matches[1]);
            }
            $this->assertMatchesRegularExpression('/Ficti|Imaginaire/u', implode(' ', array_filter([
                $lead->first_name, $lead->last_name, $lead->company,
            ])));

            $events = $lead->processingEvents;
            $this->assertNotEmpty($events);
            $this->assertSame($events->sortBy(fn ($event): string => $event->created_at->format('Y-m-d H:i:s').sprintf('%020d', $event->id))->pluck('id')->all(), $events->pluck('id')->all());
            foreach ($events as $event) {
                $this->assertEqualsCanonicalizing(array_intersect(array_keys($event->context ?? []), self::ALLOWED_CONTEXT_KEYS), array_keys($event->context ?? []));
                $serialized = json_encode($event->context, JSON_THROW_ON_ERROR);
                foreach (array_filter([$lead->email, $lead->phone, $lead->message]) as $sensitiveValue) {
                    $this->assertStringNotContainsString($sensitiveValue, $serialized);
                }
            }

            if ($lead->score !== null) {
                $score = app(LeadScorer::class)->score($lead->getAttributes());
                $this->assertSame($lead->score, $score->score);
                $this->assertSame($lead->classification, app(LeadClassifier::class)->classify($score->score));
                $this->assertSame($lead->assigned_to, app(LeadRouter::class)->route($lead->service_interest)->assignedTo);
                $this->assertSame($score->ruleCodes, $events->firstWhere('type', ProcessingEventType::ScoreCalculated)->context['rule_codes']);
            }
        }

        $hotTypes = $leads['demo-hot-001']->processingEvents->pluck('type')->all();
        $this->assertSame([
            ProcessingEventType::LeadReceived, ProcessingEventType::ProcessingStarted,
            ProcessingEventType::LeadNormalized, ProcessingEventType::ValidationSucceeded,
            ProcessingEventType::DuplicateChecked, ProcessingEventType::ScoreCalculated,
            ProcessingEventType::LeadClassified, ProcessingEventType::LeadRoutingDecided,
            ProcessingEventType::LeadQualified, ProcessingEventType::TransmissionSucceeded,
            ProcessingEventType::LeadRouted,
        ], $hotTypes);

        $failed = $leads['demo-failed-001'];
        $this->assertSame([1, 2, 3], $failed->processingEvents
            ->where('type', ProcessingEventType::TransmissionFailed)->pluck('attempt')->all());
        $this->assertSame('attempts_exhausted', $failed->processingEvents->last()->context['error_code']);
        Queue::assertNothingPushed();

        $this->get(route('dashboard'))->assertOk()->assertSee('8');
        $this->get(route('leads.index'))->assertOk()->assertSee('Aline-Fictive')->assertSee('Hana-Fictive');
        $this->get(route('exceptions.index'))->assertOk()->assertSee('Dorian-Fictif')->assertSee('Gaël-Fictif')->assertSee('Hana-Fictive');
        $this->get(route('leads.show', $leads['demo-review-001']))->assertOk()->assertSee('Enregistrer les corrections')->assertSee('Retraiter le lead')->assertDontSee('Relancer le traitement');
        $this->get(route('leads.show', $failed))->assertOk()->assertSee('Relancer le traitement')->assertDontSee('Enregistrer les corrections');
    }

    public function test_second_run_is_strictly_idempotent_and_preserves_unrelated_leads(): void
    {
        Queue::fake();
        $unrelated = Lead::factory()->create(['external_reference' => 'unrelated-fixed-reference']);
        $this->seed(LeadDemoSeeder::class);
        $first = $this->snapshot();

        $this->seed(LeadDemoSeeder::class);

        $this->assertSame($first, $this->snapshot());
        $this->assertDatabaseHas('leads', ['id' => $unrelated->id, 'external_reference' => 'unrelated-fixed-reference']);
        $this->assertDatabaseCount('leads', 9);
        Queue::assertNothingPushed();
    }

    public function test_invalid_webhook_fixture_is_rejected_without_writes(): void
    {
        Queue::fake();
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/invalid-webhook-payload.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->postJson('/api/local/webhooks/leads', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('result', 'invalid')
            ->assertJsonPath('errors.0', 'lead.external_reference');

        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('processing_events', 0);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'leads' => DB::table('leads')->whereIn('external_reference', self::REFERENCES)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'events' => DB::table('processing_events')->whereIn('lead_id', Lead::query()->whereIn('external_reference', self::REFERENCES)->pluck('id'))->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }
}
