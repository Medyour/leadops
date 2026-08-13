<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\DuplicateDetector;
use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\LeadBusinessValidator;
use App\Domain\LeadProcessing\LeadClassifier;
use App\Domain\LeadProcessing\LeadNormalizer;
use App\Domain\LeadProcessing\LeadRouter;
use App\Domain\LeadProcessing\LeadScorer;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use App\Services\Leads\SimulatedLeadTransmitter;
use Database\Seeders\LeadDemoSeeder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeadDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private const MANIFEST = [
        'demo-hot-001' => 'form',
        'demo-warm-001' => 'csv',
        'demo-low-001' => 'webhook',
        'demo-invalid-001' => 'form',
        'demo-invalid-phone-001' => 'csv',
        'demo-duplicate-001' => 'webhook',
        'demo-review-001' => 'form',
        'demo-failed-001' => 'webhook',
    ];

    /** @var array<string, string> */
    private const RECEIVED_AT = [
        'demo-hot-001' => '2026-01-15 09:00:00',
        'demo-warm-001' => '2026-01-15 09:10:00',
        'demo-low-001' => '2026-01-15 09:20:00',
        'demo-invalid-001' => '2026-01-15 09:30:00',
        'demo-invalid-phone-001' => '2026-01-15 09:40:00',
        'demo-duplicate-001' => '2026-01-15 09:50:00',
        'demo-review-001' => '2026-01-15 10:00:00',
        'demo-failed-001' => '2026-01-15 10:10:00',
    ];

    private const ALLOWED_CONTEXT_KEYS = [
        'assigned_to', 'candidate_ids', 'changed_fields', 'classification', 'cycle_id',
        'error_code', 'idempotency_key', 'reason_codes', 'reference_fingerprint',
        'retry_delay', 'rule_codes', 'score', 'service',
    ];

    public function test_manifest_is_reproducible_by_the_current_business_services(): void
    {
        Queue::fake();
        $this->seed(LeadDemoSeeder::class);

        $leads = $this->manifestLeads();
        $this->assertCount(8, $leads);
        $this->assertSame(array_keys(self::MANIFEST), $leads->keys()->all());
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

        $normalizer = app(LeadNormalizer::class);
        $validator = app(LeadBusinessValidator::class);
        $detector = app(DuplicateDetector::class);

        foreach ($leads as $lead) {
            $this->assertSame(self::RECEIVED_AT[$lead->external_reference], $lead->getRawOriginal('received_at'));
            $normalized = $normalizer->normalize($lead->getAttributes());
            foreach ($normalized as $field => $value) {
                $persistedValue = match ($field) {
                    'source' => $lead->source->value,
                    'consent' => $lead->consent,
                    default => $lead->getRawOriginal($field),
                };
                $this->assertSame($value, $persistedValue, "{$lead->external_reference}.{$field} is not normalized.");
            }

            $validation = $validator->validate($normalized);
            if (in_array($lead->external_reference, ['demo-invalid-001', 'demo-invalid-phone-001'], true)) {
                $this->assertSame(['contact.invalid'], $validation->invalidReasonCodes);
            } else {
                $this->assertSame([], $validation->invalidReasonCodes);
                $this->assertSame([], $validation->reviewReasonCodes);
            }
        }

        $this->assertSame('adresse-invalide', $leads['demo-invalid-001']->email);
        $this->assertNull($leads['demo-invalid-001']->phone);
        $this->assertNull($leads['demo-invalid-phone-001']->email);
        $this->assertSame('+1202555019999999', $leads['demo-invalid-phone-001']->phone);
        $this->assertNull($leads['demo-invalid-phone-001']->normalized_phone);

        $duplicate = $detector->detect($leads['demo-duplicate-001']);
        $this->assertSame($leads['demo-hot-001']->id, $duplicate->duplicateOf);
        $this->assertSame([$leads['demo-hot-001']->id], $duplicate->candidateIds);
        $this->assertSame($leads['demo-hot-001']->id, $leads['demo-duplicate-001']->duplicate_of);

        $review = $detector->detect($leads['demo-review-001']);
        $candidateIds = [$leads['demo-hot-001']->id, $leads['demo-warm-001']->id];
        sort($candidateIds);
        $this->assertTrue($review->needsReview());
        $this->assertSame($candidateIds, $review->candidateIds);
        $this->assertNull($leads['demo-review-001']->duplicate_of);

        foreach (['demo-hot-001', 'demo-warm-001', 'demo-low-001', 'demo-failed-001'] as $reference) {
            $lead = $leads[$reference];
            $score = app(LeadScorer::class)->score($lead->getAttributes());
            $this->assertSame($lead->score, $score->score);
            $this->assertSame($lead->classification, app(LeadClassifier::class)->classify($score->score));
            $this->assertSame($lead->assigned_to, app(LeadRouter::class)->route($lead->service_interest)->assignedTo);
            $this->assertSame(
                $score->ruleCodes,
                $lead->processingEvents->firstWhere('type', ProcessingEventType::ScoreCalculated)->context['rule_codes'],
            );
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_every_timeline_matches_the_pipeline_contract_exactly(): void
    {
        $this->seed(LeadDemoSeeder::class);
        $leads = $this->manifestLeads();
        $routedTimeline = [
            ['lead.received', null, 'RECEIVED', null, 0],
            ['processing.started', 'RECEIVED', 'VALIDATING', 1, 1],
            ['lead.normalized', 'VALIDATING', null, 1, 2],
            ['validation.succeeded', 'VALIDATING', null, 1, 3],
            ['duplicate.checked', 'VALIDATING', null, 1, 4],
            ['score.calculated', 'VALIDATING', null, 1, 5],
            ['lead.classified', 'VALIDATING', null, 1, 6],
            ['lead.routing_decided', 'VALIDATING', null, 1, 7],
            ['lead.qualified', 'VALIDATING', 'QUALIFIED', 1, 8],
            ['transmission.succeeded', 'QUALIFIED', null, 1, 9],
            ['lead.routed', 'QUALIFIED', 'ROUTED', 1, 10],
        ];
        $expected = [
            'demo-hot-001' => $routedTimeline,
            'demo-warm-001' => $routedTimeline,
            'demo-low-001' => $routedTimeline,
            'demo-invalid-001' => [
                ['lead.received', null, 'RECEIVED', null, 0],
                ['processing.started', 'RECEIVED', 'VALIDATING', 1, 1],
                ['lead.normalized', 'VALIDATING', null, 1, 2],
                ['validation.failed', 'VALIDATING', 'INVALID', 1, 3],
            ],
            'demo-invalid-phone-001' => [
                ['lead.received', null, 'RECEIVED', null, 0],
                ['processing.started', 'RECEIVED', 'VALIDATING', 1, 1],
                ['lead.normalized', 'VALIDATING', null, 1, 2],
                ['validation.failed', 'VALIDATING', 'INVALID', 1, 3],
            ],
            'demo-duplicate-001' => [
                ['lead.received', null, 'RECEIVED', null, 0],
                ['processing.started', 'RECEIVED', 'VALIDATING', 1, 1],
                ['lead.normalized', 'VALIDATING', null, 1, 2],
                ['validation.succeeded', 'VALIDATING', null, 1, 3],
                ['duplicate.detected', 'VALIDATING', 'DUPLICATE', 1, 4],
            ],
            'demo-review-001' => [
                ['lead.received', null, 'RECEIVED', null, 0],
                ['processing.started', 'RECEIVED', 'VALIDATING', 1, 1],
                ['lead.normalized', 'VALIDATING', null, 1, 2],
                ['validation.succeeded', 'VALIDATING', null, 1, 3],
                ['review.requested', 'VALIDATING', 'NEEDS_REVIEW', 1, 4],
            ],
            'demo-failed-001' => [
                ...array_slice($routedTimeline, 0, 9),
                ['transmission.failed', 'QUALIFIED', null, 1, 9],
                ['processing.retry_scheduled', 'QUALIFIED', null, 1, 10],
                ['processing.started', 'QUALIFIED', null, 2, 11],
                ['transmission.failed', 'QUALIFIED', null, 2, 12],
                ['processing.retry_scheduled', 'QUALIFIED', null, 2, 13],
                ['processing.started', 'QUALIFIED', null, 3, 18],
                ['transmission.failed', 'QUALIFIED', null, 3, 19],
                ['processing.failed', 'QUALIFIED', 'FAILED', 3, 20],
            ],
        ];

        $sensitiveValues = $leads->flatMap(fn (Lead $lead): array => array_filter([
            $lead->email, $lead->phone, $lead->message,
        ]))->unique()->values()->all();

        foreach ($leads as $reference => $lead) {
            $base = $lead->received_at->utc();
            $actual = $lead->processingEvents->map(fn (ProcessingEvent $event): array => [
                $event->type->value,
                $event->from_status?->value,
                $event->to_status?->value,
                $event->attempt,
                (int) $base->diffInSeconds($event->created_at->utc()),
            ])->all();
            $this->assertSame($expected[$reference], $actual, "Unexpected timeline for {$reference}.");

            foreach ($lead->processingEvents as $position => $event) {
                $expectedTimestamp = (new DateTimeImmutable(self::RECEIVED_AT[$reference], new DateTimeZone('UTC')))
                    ->modify(sprintf('+%d seconds', $expected[$reference][$position][4]))
                    ->format('Y-m-d H:i:s');
                $this->assertSame($expectedTimestamp, $event->getRawOriginal('created_at'));

                if ($event->from_status !== null && $event->to_status !== null) {
                    $this->assertTrue($event->from_status->canTransitionTo($event->to_status));
                }
                $this->assertSame([], array_diff(array_keys($event->context ?? []), self::ALLOWED_CONTEXT_KEYS));
                $serialized = json_encode($event->context, JSON_THROW_ON_ERROR);
                $this->assertDoesNotMatchRegularExpression('/"(?:email|phone|message|payload)"\s*:/i', $serialized);
                foreach ($sensitiveValues as $value) {
                    $this->assertStringNotContainsString($value, $serialized);
                }
            }

            $receipt = $lead->processingEvents->first();
            $this->assertSame(
                substr(hash('sha256', $lead->source->value.'|'.$reference), 0, 16),
                $receipt->context['reference_fingerprint'],
            );

            if ($lead->status === LeadStatus::Routed) {
                $transmission = $lead->processingEvents->firstWhere('type', ProcessingEventType::TransmissionSucceeded);
                $routed = $lead->processingEvents->firstWhere('type', ProcessingEventType::LeadRouted);
                $key = "lead:{$lead->id}:cycle:initial:transmission";
                $result = app(SimulatedLeadTransmitter::class)->transmit($lead, $key);
                $this->assertSame($key, $transmission->context['idempotency_key']);
                $this->assertSame(substr(hash('sha256', $result->reference), 0, 16), $transmission->context['reference_fingerprint']);
                $this->assertTrue($transmission->created_at->lt($routed->created_at));
                $this->assertTrue($lead->processed_at->equalTo($routed->created_at));
            } else {
                $this->assertNull($lead->processed_at);
            }
        }

        $failed = $leads['demo-failed-001'];
        $this->assertSame([1, 2, 3], $failed->processingEvents
            ->where('type', ProcessingEventType::TransmissionFailed)->pluck('attempt')->all());
        $this->assertSame([1, 5], $failed->processingEvents
            ->where('type', ProcessingEventType::ProcessingRetryScheduled)->pluck('context.retry_delay')->all());
        $this->assertSame('attempts_exhausted', $failed->processingEvents->last()->context['error_code']);
    }

    public function test_three_runs_are_identical_and_preserve_unrelated_records_and_source_collisions(): void
    {
        Queue::fake();
        $collision = Lead::factory()->create([
            'source' => LeadSource::Csv,
            'external_reference' => 'demo-hot-001',
            'first_name' => 'Collision-Fictive',
            'email' => 'collision-fictive@example.test',
        ]);
        $unrelated = Lead::factory()->create(['external_reference' => 'unrelated-fixed-reference']);
        $collisionEvent = ProcessingEvent::factory()->for($collision)->create(['context' => ['rule_codes' => ['preserve-me']]]);
        $unrelatedEvent = ProcessingEvent::factory()->for($unrelated)->create(['context' => ['rule_codes' => ['preserve-me-too']]]);
        $foreignSnapshot = $this->rawRows([$collision->id, $unrelated->id]);

        $this->seed(LeadDemoSeeder::class);
        $first = $this->manifestSnapshot();
        $this->seed(LeadDemoSeeder::class);
        $second = $this->manifestSnapshot();
        $this->seed(LeadDemoSeeder::class);

        $this->assertSame($first, $second);
        $this->assertSame($first, $this->manifestSnapshot());
        $this->assertSame($foreignSnapshot, $this->rawRows([$collision->id, $unrelated->id]));
        $this->assertDatabaseHas('processing_events', ['id' => $collisionEvent->id, 'lead_id' => $collision->id]);
        $this->assertDatabaseHas('processing_events', ['id' => $unrelatedEvent->id, 'lead_id' => $unrelated->id]);
        $this->assertDatabaseCount('leads', 10);
        $this->assertCount(8, $this->manifestLeads());
        Queue::assertNothingPushed();
    }

    public function test_fixed_utc_dates_do_not_depend_on_the_php_or_application_timezone(): void
    {
        $originalTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Auckland');
            config()->set('app.timezone', 'Pacific/Auckland');
            $this->seed(LeadDemoSeeder::class);

            $hot = DB::table('leads')
                ->where('source', 'form')
                ->where('external_reference', 'demo-hot-001')
                ->first();
            $this->assertNotNull($hot);
            $this->assertSame('2026-01-15 09:00:00', $hot->received_at);
            $this->assertSame('2026-01-15 09:00:10', $hot->processed_at);
            $this->assertSame(
                ['2026-01-15 09:00:00', '2026-01-15 09:00:10'],
                DB::table('processing_events')
                    ->where('lead_id', $hot->id)
                    ->orderBy('created_at')
                    ->pluck('created_at')
                    ->only([0, 10])
                    ->values()
                    ->all(),
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }
    }

    public function test_legacy_seven_lead_dataset_is_migrated_in_place_and_becomes_idempotent(): void
    {
        Queue::fake();
        $legacyIds = $this->insertLegacyDemoDataset();

        $this->seed(LeadDemoSeeder::class);
        $leads = $this->manifestLeads();

        $this->assertDatabaseCount('leads', 8);
        foreach ($legacyIds as $reference => $id) {
            $this->assertSame($id, $leads[$reference]->id);
        }
        $this->assertSame(LeadSource::Csv, $leads['demo-warm-001']->source);
        $this->assertSame(LeadSource::Webhook, $leads['demo-low-001']->source);
        $this->assertSame(LeadSource::Webhook, $leads['demo-duplicate-001']->source);
        $this->assertSame(LeadSource::Webhook, $leads['demo-failed-001']->source);
        $this->assertSame($leads['demo-hot-001']->id, $leads['demo-duplicate-001']->duplicate_of);

        $snapshot = $this->manifestSnapshot();
        $this->seed(LeadDemoSeeder::class);
        $this->assertSame($snapshot, $this->manifestSnapshot());
        Queue::assertNothingPushed();
    }

    #[DataProvider('transactionFailureProvider')]
    public function test_any_manifest_relation_or_timeline_failure_rolls_back_the_whole_seed(string $triggerSql): void
    {
        Queue::fake();
        DB::unprepared($triggerSql);

        try {
            $this->seed(LeadDemoSeeder::class);
            $this->fail('The simulated seeder failure must be propagated.');
        } catch (QueryException) {
            $this->assertDatabaseCount('leads', 0);
            $this->assertDatabaseCount('processing_events', 0);
            $this->assertDatabaseCount('jobs', 0);
            Queue::assertNothingPushed();
        } finally {
            DB::unprepared('DROP TRIGGER IF EXISTS fail_demo_seed');
        }
    }

    /** @return iterable<string, array{string}> */
    public static function transactionFailureProvider(): iterable
    {
        yield 'lead insertion' => [<<<'SQL'
            CREATE TRIGGER fail_demo_seed BEFORE INSERT ON leads
            WHEN NEW.external_reference = 'demo-warm-001'
            BEGIN SELECT RAISE(ABORT, 'simulated lead insertion failure'); END
            SQL];
        yield 'duplicate relation' => [<<<'SQL'
            CREATE TRIGGER fail_demo_seed BEFORE UPDATE ON leads
            WHEN NEW.external_reference = 'demo-duplicate-001' AND NEW.duplicate_of IS NOT NULL
            BEGIN SELECT RAISE(ABORT, 'simulated relation failure'); END
            SQL];
        yield 'timeline insertion' => [<<<'SQL'
            CREATE TRIGGER fail_demo_seed BEFORE INSERT ON processing_events
            WHEN NEW.type = 'score.calculated'
            BEGIN SELECT RAISE(ABORT, 'simulated timeline failure'); END
            SQL];
    }

    public function test_invalid_webhook_fixture_is_valid_json_but_returns_a_stable_422_without_writes(): void
    {
        Queue::fake();
        $contents = file_get_contents(base_path('tests/Fixtures/invalid-webhook-payload.json'));
        $this->assertIsString($contents);
        $this->assertTrue(json_validate($contents));
        $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $this->postJson('/api/local/webhooks/leads', $payload)
            ->assertUnprocessable()
            ->assertExactJson([
                'result' => 'invalid',
                'errors' => ['lead.external_reference'],
            ]);

        $this->assertStringEndsWith('@example.test', $payload['lead']['email']);
        $this->assertStringContainsString('+1 202-555-0198', $payload['lead']['phone']);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('processing_events', 0);
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertNothingPushed();
    }

    public function test_demo_pages_and_local_actions_render_only_for_the_expected_statuses(): void
    {
        $this->seed(LeadDemoSeeder::class);
        $leads = $this->manifestLeads();

        $this->get(route('dashboard'))->assertOk()->assertSee('8');
        $this->get(route('leads.index'))->assertOk()->assertSee('Aline-Fictive')->assertSee('Hana-Fictive');
        $this->get(route('exceptions.index'))->assertOk()->assertSee('Dorian-Fictif')->assertSee('Gaël-Fictif')->assertSee('Hana-Fictive');
        $this->get(route('leads.show', $leads['demo-review-001']))->assertOk()
            ->assertSee('Enregistrer les corrections')->assertSee('Retraiter le lead')->assertDontSee('Relancer le traitement');
        $this->get(route('leads.show', $leads['demo-failed-001']))->assertOk()
            ->assertSee('Relancer le traitement')->assertDontSee('Enregistrer les corrections');
        $this->get(route('leads.show', $leads['demo-hot-001']))->assertOk()
            ->assertDontSee('Enregistrer les corrections')->assertDontSee('Relancer le traitement');
    }

    /** @return Collection<string, Lead> */
    private function manifestLeads(): Collection
    {
        return $this->manifestQuery(Lead::query())
            ->with('processingEvents')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get()
            ->keyBy('external_reference');
    }

    private function manifestQuery(mixed $query): mixed
    {
        return $query->where(function ($query): void {
            foreach (self::MANIFEST as $reference => $source) {
                $query->orWhere(function ($query) use ($reference, $source): void {
                    $query->where('source', $source)->where('external_reference', $reference);
                });
            }
        });
    }

    /** @return array<string, mixed> */
    private function manifestSnapshot(): array
    {
        $leadIds = $this->manifestQuery(DB::table('leads'))->orderBy('id')->pluck('id');

        return [
            'leads' => DB::table('leads')->whereIn('id', $leadIds)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'events' => DB::table('processing_events')->whereIn('lead_id', $leadIds)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    /** @param list<int> $leadIds
     * @return array<string, mixed>
     */
    private function rawRows(array $leadIds): array
    {
        return [
            'leads' => DB::table('leads')->whereIn('id', $leadIds)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
            'events' => DB::table('processing_events')->whereIn('lead_id', $leadIds)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    /** @return array<string, int> */
    private function insertLegacyDemoDataset(): array
    {
        $base = [
            'source' => 'form', 'last_name' => 'Demo', 'country' => 'FR',
            'service_interest' => 'automation', 'company_size' => 12,
            'message' => 'Message entièrement fictif destiné à la démonstration locale LeadOps.',
            'consent' => true, 'received_at' => '2026-01-15 09:00:00',
            'score' => null, 'classification' => null, 'assigned_to' => null,
            'status' => 'RECEIVED', 'duplicate_of' => null, 'processing_attempts' => 0,
            'processed_at' => null, 'created_at' => '2026-01-15 09:00:00', 'updated_at' => '2026-01-15 09:00:00',
        ];
        $rows = [
            'demo-hot-001' => [
                'first_name' => 'Aline-Fictive', 'company' => 'Atelier Fictif',
                'email' => 'aline-fictive@example.test', 'phone' => '+12025550100',
                'normalized_email' => 'aline-fictive@example.test', 'normalized_phone' => '+12025550100',
                'score' => 85, 'classification' => 'HOT', 'assigned_to' => 'Sarah', 'status' => 'ROUTED',
                'processing_attempts' => 1, 'processed_at' => '2026-01-15 09:05:00',
            ],
            'demo-warm-001' => [
                'first_name' => 'Benoit-Fictif', 'company' => 'Bureau Imaginaire',
                'email' => 'benoit-fictif@example.test', 'phone' => '+12025550101',
                'normalized_email' => 'benoit-fictif@example.test', 'normalized_phone' => '+12025550101',
                'service_interest' => 'dashboard', 'score' => 65, 'classification' => 'WARM',
                'assigned_to' => 'Yassine', 'status' => 'ROUTED', 'processing_attempts' => 1,
                'processed_at' => '2026-01-15 09:06:00',
            ],
            'demo-low-001' => [
                'first_name' => 'Camille-Fictive', 'company' => null,
                'email' => 'camille-fictive@example.test', 'phone' => '+12025550102', 'country' => null,
                'normalized_email' => 'camille-fictive@example.test', 'normalized_phone' => '+12025550102',
                'service_interest' => 'other', 'company_size' => null, 'score' => 35, 'classification' => 'LOW',
                'assigned_to' => 'Emma', 'status' => 'ROUTED', 'processing_attempts' => 1,
                'processed_at' => '2026-01-15 09:07:00',
            ],
            'demo-invalid-001' => [
                'first_name' => 'Dorian-Fictif', 'company' => 'Structure Fictive',
                'email' => 'adresse-invalide', 'phone' => null, 'normalized_email' => null,
                'normalized_phone' => null, 'status' => 'INVALID',
            ],
            'demo-duplicate-001' => [
                'first_name' => 'Elise-Fictive', 'company' => 'Doublon Fictif',
                'email' => 'aline-fictive@example.test', 'phone' => '+12025550100',
                'normalized_email' => 'aline-fictive@example.test', 'normalized_phone' => '+12025550100',
                'status' => 'DUPLICATE',
            ],
            'demo-review-001' => [
                'first_name' => 'Farid-Fictif', 'company' => 'Revue Fictive',
                'email' => 'aline-fictive@example.test', 'phone' => '+12025550101',
                'normalized_email' => 'aline-fictive@example.test', 'normalized_phone' => '+12025550101',
                'service_interest' => 'api', 'status' => 'NEEDS_REVIEW',
            ],
            'demo-failed-001' => [
                'first_name' => 'Gaelle-Fictive', 'company' => 'Incident Fictif',
                'email' => 'gaelle-fictive@example.test', 'phone' => '+12025550106',
                'normalized_email' => 'gaelle-fictive@example.test', 'normalized_phone' => '+12025550106',
                'status' => 'FAILED', 'processing_attempts' => 3,
            ],
        ];

        $ids = [];
        foreach ($rows as $reference => $attributes) {
            $ids[$reference] = (int) DB::table('leads')->insertGetId([
                ...$base, ...$attributes, 'external_reference' => $reference,
            ]);
        }
        DB::table('leads')->where('id', $ids['demo-duplicate-001'])->update(['duplicate_of' => $ids['demo-hot-001']]);

        foreach (['lead.received', 'lead.normalized', 'score.calculated', 'lead.classified', 'transmission.succeeded', 'lead.routed'] as $position => $type) {
            DB::table('processing_events')->insert([
                'lead_id' => $ids['demo-hot-001'], 'type' => $type,
                'from_status' => null, 'to_status' => null, 'attempt' => 1,
                'context' => json_encode(['scenario' => 'demo', 'step' => $position + 1], JSON_THROW_ON_ERROR),
                'created_at' => sprintf('2026-01-15 09:00:%02d', $position),
            ]);
        }

        return $ids;
    }
}
