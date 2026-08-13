<?php

namespace App\Http\Controllers;

use App\Support\LocalEnvironment;
use Illuminate\Contracts\View\View;

class WebhookSimulatorController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(LocalEnvironment::allowsMutations(), 404);

        return view('tools.webhook', [
            'webhookUrl' => route('local.webhooks.leads.store', absolute: false),
        ]);
    }
}
