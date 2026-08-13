<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ProcessingEventType;
use App\Domain\LeadProcessing\Enums\ServiceInterest;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use Database\Seeders\LeadDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_lead_and_processing_event_schema_is_available_on_sqlite(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $this->assertTrue(Schema::hasColumns('leads', [
            'id',
            'source',
            'external_reference',
            'first_name',
            'last_name',
            'company',
            'email',
            'phone',
            'country',
            'service_interest',
            'company_size',
            'message',
            'consent',
            'received_at',
            'normalized_email',
            'normalized_phone',
            'score',
            'classification',
            'assigned_to',
            'status',
            'duplicate_of',
            'processing_attempts',
            'processed_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('processing_events', [
            'id',
            'lead_id',
            'type',
            'from_status',
            'to_status',
            'attempt',
            'context',
            'created_at',
        ]));

        $leadIndexes = $this->sqliteIndexes('leads');
        $this->assertSame(['source', 'external_reference'], $leadIndexes['leads_source_external_reference_unique']);
        $this->assertSame(['normalized_email'], $leadIndexes['leads_normalized_email_index']);
        $this->assertSame(['normalized_phone'], $leadIndexes['leads_normalized_phone_index']);
        $this->assertSame(['status', 'received_at'], $leadIndexes['leads_status_received_at_index']);
        $this->assertSame(['classification', 'received_at'], $leadIndexes['leads_classification_received_at_index']);
        $this->assertSame(['assigned_to', 'status'], $leadIndexes['leads_assigned_to_status_index']);

        $eventIndexes = $this->sqliteIndexes('processing_events');
        $this->assertSame(['lead_id', 'created_at'], $eventIndexes['processing_events_lead_id_created_at_index']);
        $this->assertSame(['type'], $eventIndexes['processing_events_type_index']);

        $this->assertSqliteNullability('leads', [
            'source',
            'external_reference',
            'first_name',
            'last_name',
            'service_interest',
            'consent',
            'received_at',
            'status',
            'processing_attempts',
            'created_at',
            'updated_at',
        ], [
            'company',
            'email',
            'phone',
            'country',
            'company_size',
            'message',
            'normalized_email',
            'normalized_phone',
            'score',
            'classification',
            'assigned_to',
            'duplicate_of',
            'processed_at',
        ]);
        $this->assertSqliteNullability('processing_events', [
            'lead_id',
            'type',
            'created_at',
        ], [
            'from_status',
            'to_status',
            'attempt',
            'context',
        ]);

        $leadColumns = $this->sqliteColumns('leads');
        $this->assertSame("'RECEIVED'", $leadColumns['status']->dflt_value);
        $this->assertSame("'0'", $leadColumns['consent']->dflt_value);
        $this->assertSame("'0'", $leadColumns['processing_attempts']->dflt_value);
        $this->assertSame('CURRENT_TIMESTAMP', $leadColumns['received_at']->dflt_value);
        $this->assertSame('CURRENT_TIMESTAMP', $leadColumns['created_at']->dflt_value);
        $this->assertSame('CURRENT_TIMESTAMP', $leadColumns['updated_at']->dflt_value);
    }

    public function test_database_defaults_and_model_casts_match_the_domain_contract(): void
    {
        $lead = Lead::query()->create([
            'source' => LeadSource::Webhook,
            'external_reference' => 'defaults-001',
            'first_name' => 'Prenom-Fictif',
            'last_name' => 'Nom-Fictif',
            'service_interest' => ServiceInterest::Api->value,
        ])->refresh();

        $this->assertSame(LeadSource::Webhook, $lead->source);
        $this->assertSame(LeadStatus::Received, $lead->status);
        $this->assertFalse($lead->consent);
        $this->assertSame(0, $lead->processing_attempts);
        $this->assertNotNull($lead->received_at);

        $lead->update([
            'consent' => true,
            'company_size' => 15,
            'score' => 80,
            'classification' => LeadClassification::Hot,
            'processed_at' => now(),
        ]);

        $lead->refresh();
        $this->assertTrue($lead->consent);
        $this->assertSame(15, $lead->company_size);
        $this->assertSame(80, $lead->score);
        $this->assertSame(LeadClassification::Hot, $lead->classification);
        $this->assertNotNull($lead->processed_at);
    }

    public function test_service_interest_remains_a_string_for_unknown_review_values(): void
    {
        $lead = Lead::factory()->needsReview()->create()->refresh();

        $this->assertSame('service_inconnu', $lead->service_interest);
        $this->assertIsString($lead->service_interest);
        $this->assertSame('service_inconnu', $lead->getRawOriginal('service_interest'));
    }

    public function test_source_and_external_reference_form_an_idempotency_constraint(): void
    {
        Lead::factory()->create([
            'source' => LeadSource::Csv,
            'external_reference' => 'batch-001-row-001',
        ]);

        $this->expectException(QueryException::class);

        Lead::factory()->create([
            'source' => LeadSource::Csv,
            'external_reference' => 'batch-001-row-001',
        ]);
    }

    public function test_same_external_reference_is_allowed_for_different_sources(): void
    {
        Lead::factory()->create([
            'source' => LeadSource::Form,
            'external_reference' => 'shared-reference',
        ]);
        Lead::factory()->create([
            'source' => LeadSource::Webhook,
            'external_reference' => 'shared-reference',
        ]);

        $this->assertDatabaseCount('leads', 2);
    }

    public function test_model_relations_and_foreign_key_behaviour_are_consistent(): void
    {
        $origin = Lead::factory()->create();
        $duplicate = Lead::factory()->duplicateOf($origin)->create();

        $firstEvent = ProcessingEvent::factory()->for($origin)->create([
            'type' => ProcessingEventType::LeadReceived,
            'created_at' => now()->subSecond(),
        ]);
        $secondEvent = ProcessingEvent::factory()->for($origin)->create([
            'type' => ProcessingEventType::LeadNormalized,
            'created_at' => now(),
        ]);

        $this->assertTrue($duplicate->duplicateOf->is($origin));
        $this->assertTrue($origin->duplicates->contains($duplicate));
        $this->assertSame([$firstEvent->id, $secondEvent->id], $origin->processingEvents->modelKeys());
        $this->assertTrue($firstEvent->lead->is($origin));

        try {
            $origin->delete();
            $this->fail('Deleting a lead referenced as a duplicate origin should be restricted.');
        } catch (QueryException) {
            $this->assertDatabaseHas('leads', ['id' => $origin->id]);
        }

        $duplicate->delete();
        $origin->delete();
        $this->assertDatabaseMissing('processing_events', ['lead_id' => $origin->id]);
    }

    public function test_processing_event_casts_statuses_type_and_safe_context(): void
    {
        $event = ProcessingEvent::factory()->create([
            'type' => ProcessingEventType::ScoreCalculated,
            'from_status' => LeadStatus::Validating,
            'to_status' => LeadStatus::Qualified,
            'attempt' => 2,
            'context' => ['score' => 65, 'rule_codes' => ['valid_email:+10']],
        ])->refresh();

        $this->assertSame(ProcessingEventType::ScoreCalculated, $event->type);
        $this->assertSame(LeadStatus::Validating, $event->from_status);
        $this->assertSame(LeadStatus::Qualified, $event->to_status);
        $this->assertSame(2, $event->attempt);
        $this->assertSame(['score' => 65, 'rule_codes' => ['valid_email:+10']], $event->context);
        $this->assertNotNull($event->created_at);
    }

    public function test_factories_provide_each_documented_fictitious_state(): void
    {
        $origin = Lead::factory()->valid()->create();
        $leads = collect([
            Lead::factory()->lowPriority()->create(),
            Lead::factory()->invalidEmail()->create(),
            Lead::factory()->invalidPhone()->create(),
            Lead::factory()->duplicateOf($origin)->create(),
            Lead::factory()->needsReview()->create(),
            Lead::factory()->failed()->create(),
            Lead::factory()->routed()->create(),
        ]);

        $this->assertSame(LeadClassification::Low, $leads[0]->classification);
        $this->assertSame(LeadStatus::Invalid, $leads[1]->status);
        $this->assertSame(LeadStatus::Invalid, $leads[2]->status);
        $this->assertSame(LeadStatus::Duplicate, $leads[3]->status);
        $this->assertSame(LeadStatus::NeedsReview, $leads[4]->status);
        $this->assertSame(LeadStatus::Failed, $leads[5]->status);
        $this->assertSame(LeadStatus::Routed, $leads[6]->status);

        $allLeads = $leads->prepend($origin);
        foreach ($allLeads as $lead) {
            $this->assertStringContainsString('Fictive', $lead->company ?? 'Entreprise Fictive absente');
            if (filter_var($lead->email, FILTER_VALIDATE_EMAIL)) {
                $this->assertStringEndsWith('@example.test', $lead->email);
            }
            if ($lead->normalized_phone !== null) {
                $this->assertMatchesRegularExpression('/^\+120255501\d{2}$/', $lead->normalized_phone);
            }
        }
    }

    public function test_demo_seeder_contains_only_stable_fictitious_scenarios(): void
    {
        $this->seed(LeadDemoSeeder::class);

        $firstLeadSnapshot = $this->tableSnapshot('leads', 'external_reference');
        $firstEventSnapshot = $this->tableSnapshot('processing_events', 'id');

        $this->seed(LeadDemoSeeder::class);

        $this->assertDatabaseCount('leads', 8);
        $this->assertDatabaseHas('leads', ['classification' => 'HOT', 'assigned_to' => 'Sarah', 'status' => 'ROUTED']);
        $this->assertDatabaseHas('leads', ['classification' => 'WARM', 'assigned_to' => 'Yassine', 'status' => 'ROUTED']);
        $this->assertDatabaseHas('leads', ['classification' => 'LOW', 'assigned_to' => 'Emma', 'status' => 'ROUTED']);
        $this->assertDatabaseHas('leads', ['status' => 'INVALID']);
        $this->assertDatabaseHas('leads', ['status' => 'DUPLICATE']);
        $this->assertDatabaseHas('leads', ['status' => 'NEEDS_REVIEW']);
        $this->assertDatabaseHas('leads', ['status' => 'FAILED']);
        $this->assertSame(2, Lead::query()->where('status', 'INVALID')->count());
        $this->assertDatabaseCount('processing_events', 68);
        $this->assertSame($firstLeadSnapshot, $this->tableSnapshot('leads', 'external_reference'));
        $this->assertSame($firstEventSnapshot, $this->tableSnapshot('processing_events', 'id'));

        $hot = Lead::query()->where('external_reference', 'demo-hot-001')->sole();
        $warm = Lead::query()->where('external_reference', 'demo-warm-001')->sole();
        $review = Lead::query()->where('external_reference', 'demo-review-001')->sole();
        $this->assertSame($hot->normalized_email, $review->normalized_email);
        $this->assertSame($warm->normalized_phone, $review->normalized_phone);
        $this->assertNotSame($hot->getKey(), $warm->getKey());
        $this->assertNull($review->duplicate_of);

        Lead::query()->whereNotNull('email')->each(function (Lead $lead): void {
            if (filter_var($lead->email, FILTER_VALIDATE_EMAIL)) {
                $this->assertStringEndsWith('@example.test', $lead->email);
            }

            if ($lead->normalized_phone !== null) {
                $this->assertMatchesRegularExpression('/^\+120255501\d{2}$/', $lead->normalized_phone);
            }
        });
    }

    public function test_business_configuration_matches_the_versioned_rules(): void
    {
        $this->assertSame(20, config('leadops.score.base'));
        $this->assertSame([0, 100], [config('leadops.score.minimum'), config('leadops.score.maximum')]);
        $this->assertSame(80, config('leadops.score.long_message_threshold'));
        $this->assertSame(
            ['gmail.com', 'outlook.com', 'hotmail.com', 'yahoo.com', 'icloud.com'],
            config('leadops.score.generic_email_domains'),
        );
        $this->assertSame(array_column(ServiceInterest::cases(), 'value'), config('leadops.qualification.services'));
        $this->assertSame(['HOT' => 80, 'WARM' => 50, 'LOW' => 0], config('leadops.qualification.classification_thresholds'));
        $this->assertSame('Sarah', config('leadops.routing.api'));
        $this->assertSame('Yassine', config('leadops.routing.dashboard'));
        $this->assertSame('Emma', config('leadops.routing.other'));
    }

    /** @return array<string, list<string>> */
    private function sqliteIndexes(string $table): array
    {
        $indexes = [];

        foreach (DB::select("PRAGMA index_list('{$table}')") as $index) {
            $indexes[$index->name] = array_map(
                static fn (object $column): string => $column->name,
                DB::select("PRAGMA index_info('{$index->name}')"),
            );
        }

        return $indexes;
    }

    /** @return array<string, object> */
    private function sqliteColumns(string $table): array
    {
        $columns = [];

        foreach (DB::select("PRAGMA table_info('{$table}')") as $column) {
            $columns[$column->name] = $column;
        }

        return $columns;
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $nullable
     */
    private function assertSqliteNullability(string $table, array $required, array $nullable): void
    {
        $columns = $this->sqliteColumns($table);

        foreach ($required as $column) {
            $this->assertSame(1, $columns[$column]->notnull, "{$table}.{$column} should be required.");
        }

        foreach ($nullable as $column) {
            $this->assertSame(0, $columns[$column]->notnull, "{$table}.{$column} should be nullable.");
        }
    }

    /** @return list<array<string, mixed>> */
    private function tableSnapshot(string $table, string $orderBy): array
    {
        return DB::table($table)
            ->orderBy($orderBy)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();
    }
}
