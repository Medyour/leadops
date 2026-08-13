<?php

namespace App\Http\Controllers;

use App\Http\Requests\RetryLeadRequest;
use App\Models\Lead;
use App\Services\Leads\LeadExceptionActionService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class LeadRetryController extends Controller
{
    public function store(
        RetryLeadRequest $request,
        Lead $lead,
        LeadExceptionActionService $actions,
    ): RedirectResponse {
        try {
            $actions->retry($lead);
        } catch (Throwable) {
            return back()->withErrors(['action' => 'Cette reprise n’est plus disponible.']);
        }

        return to_route('leads.show', $lead)
            ->with('status', 'La reprise du traitement a été demandée.');
    }
}
