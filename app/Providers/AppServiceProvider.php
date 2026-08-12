<?php

namespace App\Providers;

use App\Domain\LeadProcessing\Contracts\LeadTransmitter;
use App\Services\Leads\SimulatedLeadTransmitter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LeadTransmitter::class, SimulatedLeadTransmitter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
