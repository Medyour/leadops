<?php

namespace Tests\Feature;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Models\Lead;
use App\Models\ProcessingEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardAndLeadListsTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_routes_render_the_dashboard_lead_list_and_exception_queue(): void
    {
        $this->get(route('dashboard'))->assertOk()->assertSee('Tableau de bord');
        $this->get(route('leads.index'))->assertOk()->assertSee('Tous les leads');
        $this->get(route('exceptions.index'))->assertOk()->assertSee('File des exceptions');

        $this->assertSame('/', route('dashboard', absolute: false));
        $this->assertSame('/leads', route('leads.index', absolute: false));
        $this->assertSame('/exceptions', route('exceptions.index', absolute: false));
    }

    public function test_dashboard_aggregates_are_exact_and_include_zero_categories(): void
    {
        $routed = Lead::factory()->count(2)->routed()->create([
            'source' => LeadSource::Form,
            'classification' => LeadClassification::Hot,
        ]);
        Lead::factory()->needsReview()->create(['source' => LeadSource::Csv]);
        Lead::factory()->failed()->create(['source' => LeadSource::Csv]);

        $routed->each(fn (Lead $lead) => ProcessingEvent::factory()->count(5)->for($lead)->create());

        $response = $this->get(route('dashboard'))
            ->assertOk()
            ->assertViewHas('totalLeads', 4)
            ->assertViewHas('totalExceptions', 2)
            ->assertViewHas('statusCounts', fn (array $counts): bool => $counts['ROUTED'] === 2
                && $counts['NEEDS_REVIEW'] === 1
                && $counts['FAILED'] === 1
                && $counts['INVALID'] === 0
                && $counts['DUPLICATE'] === 0)
            ->assertViewHas('classificationCounts', [
                'HOT' => 2,
                'WARM' => 0,
                'LOW' => 0,
            ])
            ->assertViewHas('sourceCounts', [
                'form' => 2,
                'csv' => 2,
                'webhook' => 0,
            ])
            ->assertSee(route('leads.index', ['status' => 'INVALID']), false)
            ->assertSee(route('leads.index', ['classification' => 'WARM']), false)
            ->assertSee(route('leads.index', ['source' => 'webhook']), false);

        foreach ([
            'status' => $response->viewData('statusCounts'),
            'classification' => $response->viewData('classificationCounts'),
            'source' => $response->viewData('sourceCounts'),
        ] as $filter => $counts) {
            foreach ($counts as $value => $count) {
                $this->get(route('leads.index', [$filter => $value]))
                    ->assertOk()
                    ->assertViewHas('leads', fn ($leads): bool => $leads->total() === $count);
            }
        }

        $this->get(route('exceptions.index'))
            ->assertOk()
            ->assertViewHas('leads', fn ($leads): bool => $leads->total() === $response->viewData('totalExceptions'));
    }

    public function test_each_lead_enum_and_assignment_filter_is_applied(): void
    {
        $target = Lead::factory()->routed()->create([
            'external_reference' => 'filter-target',
            'source' => LeadSource::Webhook,
            'classification' => LeadClassification::Warm,
            'assigned_to' => 'Yassine',
        ]);
        Lead::factory()->failed()->create([
            'external_reference' => 'filter-other',
            'source' => LeadSource::Form,
            'classification' => LeadClassification::Low,
            'assigned_to' => 'Emma',
        ]);

        foreach ([
            ['status' => LeadStatus::Routed->value],
            ['classification' => LeadClassification::Warm->value],
            ['source' => LeadSource::Webhook->value],
            ['assigned_to' => 'Yassine'],
        ] as $filter) {
            $this->get(route('leads.index', $filter))
                ->assertOk()
                ->assertSee($target->external_reference)
                ->assertDontSee('filter-other');
        }
    }

    public function test_search_is_limited_to_reference_name_company_and_email(): void
    {
        $searchable = [
            'external_reference' => 'ref-needle',
            'first_name' => 'FirstNeedle',
            'last_name' => 'LastNeedle',
            'company' => 'CompanyNeedle',
            'email' => 'email-needle@example.test',
        ];

        foreach ($searchable as $field => $needle) {
            Lead::query()->delete();
            Lead::factory()->create([$field => $needle]);
            Lead::factory()->create(['external_reference' => 'unrelated-ref']);

            $this->get(route('leads.index', ['search' => $needle]))
                ->assertOk()
                ->assertSee($needle)
                ->assertDontSee('unrelated-ref');
        }

        Lead::query()->delete();
        Lead::factory()->create([
            'external_reference' => 'phone-only-result',
            'phone' => '+12025551234',
        ]);

        $this->get(route('leads.index', ['search' => '+12025551234']))
            ->assertOk()
            ->assertDontSee('phone-only-result')
            ->assertSee('Aucun lead trouvé');
    }

    public function test_invalid_filter_values_are_rejected_strictly(): void
    {
        foreach ([
            ['status' => 'routed'],
            ['classification' => 'VERY_HOT'],
            ['source' => 'crm'],
            ['assigned_to' => 'Utilisateur inconnu'],
            ['search' => str_repeat('x', 101)],
        ] as $filter) {
            $key = array_key_first($filter);
            $this->get(route('leads.index', $filter))
                ->assertRedirect()
                ->assertSessionHasErrors($key);
        }

        $this->get(route('exceptions.index', ['status' => LeadStatus::Routed->value]))
            ->assertRedirect()
            ->assertSessionHasErrors('status');

        $this->get(route('exceptions.index', ['status' => ['FAILED', 'ROUTED']]))
            ->assertRedirect()
            ->assertSessionHasErrors('status');
    }

    public function test_assigned_to_validation_uses_the_configured_routing_values(): void
    {
        config(['leadops.routing' => [
            'automation' => 'Nova',
            'api' => 'Nova',
        ]]);
        Lead::factory()->routed()->create([
            'external_reference' => 'configured-assignee',
            'assigned_to' => 'Nova',
        ]);

        $this->get(route('leads.index', ['assigned_to' => 'Nova']))
            ->assertOk()
            ->assertSee('configured-assignee')
            ->assertSee('<option value="Nova" selected', false);

        $this->get(route('leads.index', ['assigned_to' => 'Sarah']))
            ->assertRedirect()
            ->assertSessionHasErrors('assigned_to');
    }

    public function test_combined_filters_only_return_matching_leads(): void
    {
        Lead::factory()->needsReview()->create([
            'external_reference' => 'combined-match',
            'source' => LeadSource::Csv,
            'classification' => LeadClassification::Warm,
            'assigned_to' => 'Yassine',
            'company' => 'Studio Needle',
        ]);
        Lead::factory()->needsReview()->create([
            'external_reference' => 'wrong-source',
            'source' => LeadSource::Form,
            'classification' => LeadClassification::Warm,
            'assigned_to' => 'Yassine',
            'company' => 'Studio Needle',
        ]);

        $filters = [
            'status' => 'NEEDS_REVIEW',
            'classification' => 'WARM',
            'source' => 'csv',
            'assigned_to' => 'Yassine',
            'search' => 'Needle',
        ];

        $this->get(route('leads.index', $filters))
            ->assertOk()
            ->assertSee('combined-match')
            ->assertDontSee('wrong-source');
    }

    public function test_pagination_keeps_the_query_string_and_uses_a_reasonable_page_size(): void
    {
        Lead::factory()->count(21)->routed()->create();

        $response = $this->get(route('leads.index', [
            'status' => 'ROUTED',
            'search' => 'example.test',
        ]));

        $response->assertOk()
            ->assertViewHas('leads', fn ($leads): bool => $leads->count() === 20 && $leads->perPage() === 20)
            ->assertSee('status=ROUTED', false)
            ->assertSee('search=example.test', false)
            ->assertSee('page=2', false);
    }

    public function test_pagination_never_duplicates_the_page_parameter(): void
    {
        Lead::factory()->count(45)->routed()->create();

        $html = $this->get(route('leads.index', [
            'status' => 'ROUTED',
            'page' => 2,
        ]))->assertOk()->getContent();

        preg_match_all('/href="([^"]*page=[^"]*)"/', $html, $matches);
        $this->assertNotEmpty($matches[1]);

        foreach ($matches[1] as $href) {
            preg_match_all('/(?:\?|&amp;)page=/', $href, $pageParameters);
            $this->assertCount(1, $pageParameters[0], $href);
            $this->assertStringContainsString('status=ROUTED', $href);
        }
    }

    public function test_search_treats_sql_wildcards_as_literals_and_is_case_insensitive_on_sqlite(): void
    {
        foreach ([
            '%' => ['literal%-needle', 'literalX-needle'],
            '_' => ['literal_needle', 'literalXneedle'],
            '\\' => ['literal\\needle', 'literalXneedle'],
        ] as $search => [$matchingReference, $otherReference]) {
            Lead::query()->delete();
            Lead::factory()->create(['external_reference' => $matchingReference]);
            Lead::factory()->create(['external_reference' => $otherReference]);

            $this->get(route('leads.index', ['search' => $search]))
                ->assertOk()
                ->assertSee($matchingReference)
                ->assertDontSee($otherReference);
        }

        Lead::query()->delete();
        Lead::factory()->create(['external_reference' => 'MixedCaseNeedle']);
        Lead::factory()->create(['external_reference' => 'unrelated-case']);

        $this->get(route('leads.index', ['search' => 'mixedcaseneedle']))
            ->assertOk()
            ->assertSee('MixedCaseNeedle')
            ->assertDontSee('unrelated-case');
    }

    public function test_results_are_ordered_by_received_at_then_descending_id(): void
    {
        $older = Lead::factory()->create([
            'external_reference' => 'older-lead',
            'received_at' => '2026-01-01 08:00:00',
        ]);
        $firstTie = Lead::factory()->create([
            'external_reference' => 'first-tie',
            'received_at' => '2026-01-02 08:00:00',
        ]);
        $secondTie = Lead::factory()->create([
            'external_reference' => 'second-tie',
            'received_at' => '2026-01-02 08:00:00',
        ]);

        $this->assertGreaterThan($firstTie->id, $secondTie->id);
        $this->get(route('leads.index'))
            ->assertOk()
            ->assertSeeInOrder([$secondTie->external_reference, $firstTie->external_reference, $older->external_reference]);
    }

    public function test_exception_queue_has_a_strict_scope_and_relevant_filters(): void
    {
        foreach ([LeadStatus::Invalid, LeadStatus::Duplicate, LeadStatus::NeedsReview, LeadStatus::Failed] as $status) {
            Lead::factory()->create([
                'external_reference' => 'exception-'.$status->value,
                'status' => $status,
                'source' => LeadSource::Csv,
                'company' => 'Exception Needle',
            ]);
        }
        Lead::factory()->routed()->create(['external_reference' => 'not-an-exception']);

        $this->get(route('exceptions.index'))
            ->assertOk()
            ->assertSee('exception-INVALID')
            ->assertSee('exception-DUPLICATE')
            ->assertSee('exception-NEEDS_REVIEW')
            ->assertSee('exception-FAILED')
            ->assertDontSee('not-an-exception')
            ->assertSee('Données métier invalides')
            ->assertSee('Échec technique du traitement');

        $this->get(route('exceptions.index', [
            'status' => 'FAILED',
            'source' => 'csv',
            'search' => 'Needle',
        ]))
            ->assertOk()
            ->assertSee('exception-FAILED')
            ->assertDontSee('exception-INVALID');
    }

    public function test_lists_do_not_trigger_n_plus_one_queries_and_escape_user_content(): void
    {
        $script = '<script>alert("list")</script>';
        Lead::factory()->count(20)->create();
        Lead::factory()->failed()->create([
            'first_name' => $script,
            'company' => '<img src=x onerror=alert("company")>',
            'external_reference' => 'escaped-exception',
        ]);

        foreach (['leads.index', 'exceptions.index'] as $routeName) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get(route($routeName));
            $selects = collect(DB::getQueryLog())
                ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select'));

            $response->assertOk()
                ->assertSee(e($script), false)
                ->assertDontSee($script, false);
            $this->assertLessThanOrEqual(2, $selects->count(), $selects->pluck('query')->implode("\n"));

            $dataQuery = $selects->first(fn (array $query): bool => str_contains(strtolower($query['query']), ' limit '));
            $this->assertNotNull($dataQuery);
            $this->assertStringNotContainsString('select *', strtolower($dataQuery['query']));
            foreach (['email', 'phone', 'message', 'normalized_email', 'normalized_phone'] as $unusedColumn) {
                $this->assertStringNotContainsString('"'.$unusedColumn.'"', $dataQuery['query']);
            }
        }
    }

    public function test_tables_filters_and_pagination_have_accessible_structure(): void
    {
        Lead::factory()->count(21)->routed()->create();

        $this->get(route('leads.index'))
            ->assertOk()
            ->assertSee('<caption class="sr-only">Liste filtrée des leads</caption>', false)
            ->assertSee('<th scope="col"', false)
            ->assertSee('<th scope="row"', false)
            ->assertSee('label for="search"', false)
            ->assertSee('label for="status"', false)
            ->assertSee('label for="classification"', false)
            ->assertSee('label for="source"', false)
            ->assertSee('label for="assigned_to"', false)
            ->assertSee('role="navigation"', false)
            ->assertSee('aria-label="Pagination Navigation"', false);
    }
}
