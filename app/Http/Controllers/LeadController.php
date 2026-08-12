<?php

namespace App\Http\Controllers;

use App\Domain\LeadProcessing\Enums\LeadClassification;
use App\Domain\LeadProcessing\Enums\LeadSource;
use App\Domain\LeadProcessing\Enums\LeadStatus;
use App\Domain\LeadProcessing\Enums\ServiceInterest;
use App\Http\Requests\LeadIndexRequest;
use App\Http\Requests\StoreLeadRequest;
use App\Models\Lead;
use App\Queries\LeadListQuery;
use App\Services\Leads\LeadIngestionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class LeadController extends Controller
{
    public function index(LeadIndexRequest $request, LeadListQuery $leadListQuery): View
    {
        $filters = $request->validated();
        $query = $leadListQuery->apply(Lead::query(), $filters);

        return view('leads.index', [
            'leads' => $query->paginate(20)->withQueryString(),
            'filters' => $filters,
            'statuses' => LeadStatus::cases(),
            'classifications' => LeadClassification::cases(),
            'sources' => LeadSource::cases(),
            'assignees' => array_values(array_unique(config('leadops.routing', []))),
        ]);
    }

    public function create(): View
    {
        return view('leads.create', [
            'externalReference' => (string) Str::uuid(),
            'services' => ServiceInterest::cases(),
        ]);
    }

    public function store(
        StoreLeadRequest $request,
        LeadIngestionService $ingestion,
    ): RedirectResponse {
        $validated = $request->validated();
        $externalReference = $validated['external_reference'];
        unset($validated['external_reference']);

        $result = $ingestion->ingest(
            LeadSource::Form,
            $externalReference,
            $validated,
        );

        return to_route('leads.show', $result->lead)
            ->with(
                'status',
                $result->created
                    ? 'Le lead a bien été reçu et son traitement va commencer.'
                    : 'Ce lead avait déjà été reçu. Aucun nouveau traitement n’a été lancé.',
            );
    }

    public function show(Lead $lead): View
    {
        $lead->load([
            'processingEvents',
            'duplicateOf:id,status',
        ]);

        return view('leads.show', compact('lead'));
    }
}
