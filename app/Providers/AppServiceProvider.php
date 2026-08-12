<?php

namespace App\Providers;

use App\Domain\LeadProcessing\Contracts\LeadTransmitter;
use App\Services\Leads\SimulatedLeadTransmitter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('lead-form', function (Request $request): Limit {
            $clientKey = hash_hmac(
                'sha256',
                $request->ip(),
                (string) config('app.key'),
            );

            return Limit::perMinute(5)->by('lead-form:'.$clientKey);
        });
    }
}
