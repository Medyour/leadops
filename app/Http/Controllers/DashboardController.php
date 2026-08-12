<?php

namespace App\Http\Controllers;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Http\Requests\ExceptionIndexRequest;
use App\Models\Lead;
use Illuminate\Contracts\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $exceptionValues = array_map(
            static fn (LeadStatus $status): string => $status->value,
            ExceptionIndexRequest::statuses(),
        );
        $placeholders = implode(',', array_fill(0, count($exceptionValues), '?'));
        $totals = Lead::query()
            ->selectRaw("count(*) as total, sum(case when status in ({$placeholders}) then 1 else 0 end) as exceptions", $exceptionValues)
            ->first();

        return view('dashboard.index', [
            'totalLeads' => (int) $totals->total,
            'totalExceptions' => (int) $totals->exceptions,
            'statusCounts' => $this->countsFor('status', LeadStatus::cases()),
            'classificationCounts' => $this->countsFor('classification', LeadClassification::cases()),
            'sourceCounts' => $this->countsFor('source', LeadSource::cases()),
        ]);
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<string, int>
     */
    private function countsFor(string $column, array $cases): array
    {
        $counts = Lead::query()
            ->selectRaw("{$column}, count(*) as aggregate")
            ->groupBy($column)
            ->pluck('aggregate', $column);

        return collect($cases)
            ->mapWithKeys(fn (\BackedEnum $case): array => [$case->value => (int) $counts->get($case->value, 0)])
            ->all();
    }
}
