<?php

use App\Http\Controllers\LeadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/leads/create', [LeadController::class, 'create'])->name('leads.create');
Route::post('/leads', [LeadController::class, 'store'])
    ->middleware('throttle:lead-form')
    ->name('leads.store');

Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
