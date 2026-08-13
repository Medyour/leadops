<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLeadReviewRequest;
use App\Models\Lead;
use App\Services\Leads\LeadExceptionActionService;
use Illuminate\Http\RedirectResponse;
use Throwable;

class LeadReviewController extends Controller
{
    public function update(
        UpdateLeadReviewRequest $request,
        Lead $lead,
        LeadExceptionActionService $actions,
    ): RedirectResponse {
        try {
            $actions->correct($lead, $request->validated());
        } catch (Throwable) {
            return back()->withErrors(['action' => 'Cette correction n’est plus disponible.']);
        }

        return to_route('leads.show', $lead)
            ->with('status', 'Les informations du lead ont été enregistrées.');
    }
}
