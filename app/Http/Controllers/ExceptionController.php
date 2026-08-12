<?php

namespace App\Http\Controllers;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Http\Requests\ExceptionIndexRequest;
use App\Models\Lead;
use App\Queries\LeadListQuery;
use Illuminate\Contracts\View\View;

class ExceptionController extends Controller
{
    public function index(ExceptionIndexRequest $request, LeadListQuery $leadListQuery): View
    {
        $filters = $request->validated();
        $exceptionStatuses = ExceptionIndexRequest::statuses();
        $query = $leadListQuery->apply(
            Lead::query()->whereIn('status', $exceptionStatuses),
            $filters,
        );

        return view('exceptions.index', [
            'leads' => $query->paginate(20)->withQueryString(),
            'filters' => $filters,
            'statuses' => $exceptionStatuses,
            'classifications' => LeadClassification::cases(),
            'sources' => LeadSource::cases(),
            'assignees' => array_values(array_unique(config('leadops.routing', []))),
        ]);
    }
}
