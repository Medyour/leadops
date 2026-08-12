<?php

namespace App\Http\Controllers;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Http\Requests\ExceptionIndexRequest;
use App\Models\Lead;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class ExceptionController extends Controller
{
    public function index(ExceptionIndexRequest $request): View
    {
        $filters = $request->validated();
        $exceptionStatuses = ExceptionIndexRequest::statuses();
        $query = Lead::query()->whereIn('status', $exceptionStatuses);

        $this->applyFilters($query, $filters);

        return view('exceptions.index', [
            'leads' => $query->orderByDesc('received_at')->orderByDesc('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
            'statuses' => $exceptionStatuses,
            'classifications' => LeadClassification::cases(),
            'sources' => LeadSource::cases(),
            'assignees' => array_values(array_unique(config('leadops.routing', []))),
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['status', 'classification', 'source', 'assigned_to'] as $filter) {
            $query->when($filters[$filter] ?? null, fn (Builder $query, string $value) => $query->where($filter, $value));
        }

        $query->when($filters['search'] ?? null, function (Builder $query, string $search): void {
            $query->where(function (Builder $query) use ($search): void {
                $query->where('external_reference', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('company', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        });
    }
}
