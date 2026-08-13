<?php

use App\Http\Controllers\LeadReprocessController;
use App\Http\Controllers\LeadRetryController;
use App\Http\Controllers\LeadReviewController;
use App\Http\Controllers\WebhookSimulatorController;
use Illuminate\Support\Facades\Route;

Route::get('/tools/webhook', WebhookSimulatorController::class)
    ->name('tools.webhook');

Route::patch('/leads/{lead}/review', [LeadReviewController::class, 'update'])
    ->middleware('throttle:exception-actions')
    ->name('leads.review.update');
Route::post('/leads/{lead}/reprocess', [LeadReprocessController::class, 'store'])
    ->middleware('throttle:exception-actions')
    ->name('leads.reprocess');
Route::post('/leads/{lead}/retry', [LeadRetryController::class, 'store'])
    ->middleware('throttle:exception-actions')
    ->name('leads.retry');
