<?php

use App\Http\Controllers\LocalWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/local/webhooks/leads', [LocalWebhookController::class, 'store'])
    ->middleware('throttle:local-webhook')
    ->name('local.webhooks.leads.store');
