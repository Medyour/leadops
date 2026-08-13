<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReprocessLeadRequest;
use App\Models\Lead;
use App\Services\Leads\LeadExceptionActionService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class LeadReprocessController extends Controller
{
    public function store(
        ReprocessLeadRequest $request,
        Lead $lead,
        LeadExceptionActionService $actions,
    ): RedirectResponse {
        try {
            $actions->reprocess($lead);
        } catch (Throwable) {
            return back()->withErrors(['action' => 'Ce retraitement n’est plus disponible.']);
        }

        return to_route('leads.show', $lead)
            ->with('status', 'Le retraitement du lead a été demandé.');
    }
}
