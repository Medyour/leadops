<?php

use App\Http\Controllers\LocalWebhookController;
use App\Http\Middleware\EnsureLocalEnvironment;
use Illuminate\Support\Facades\Route;

Route::post('/local/webhooks/leads', [LocalWebhookController::class, 'store'])
    ->middleware(EnsureLocalEnvironment::class, 'throttle:local-webhook')
    ->name('local.webhooks.leads.store');
