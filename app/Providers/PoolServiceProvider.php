<?php

namespace App\Providers;

use App\Pool\Config\RulesConfig;
use App\Support\Clock;
use App\Support\SystemClock;
use Illuminate\Support\ServiceProvider;

class PoolServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(RulesConfig::class, fn () => RulesConfig::fromArray(config('pool_rules')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Resolve now, not on first use, so a bad rules config stops startup (web and artisan alike).
        $this->app->make(RulesConfig::class);
    }
}
