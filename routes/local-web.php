<?php

use App\Http\Controllers\WebhookSimulatorController;
use Illuminate\Support\Facades\Route;

Route::get('/tools/webhook', WebhookSimulatorController::class)
    ->name('tools.webhook');
