<?php

use App\Http\Controllers\CsvImportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExceptionController;
use App\Http\Controllers\LeadController;
use App\Http\Middleware\EnsureLocalEnvironment;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

Route::get('/leads', [LeadController::class, 'index'])->name('leads.index');
Route::get('/exceptions', [ExceptionController::class, 'index'])->name('exceptions.index');

Route::middleware(EnsureLocalEnvironment::class)->group(function (): void {
    Route::get('/leads/create', [LeadController::class, 'create'])->name('leads.create');
    Route::post('/leads', [LeadController::class, 'store'])
        ->middleware('throttle:lead-form')
        ->name('leads.store');

    Route::get('/imports/csv', [CsvImportController::class, 'create'])->name('imports.csv.create');
    Route::post('/imports/csv', [CsvImportController::class, 'store'])
        ->middleware('throttle:csv-import')
        ->name('imports.csv.store');
});

Route::get('/leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
