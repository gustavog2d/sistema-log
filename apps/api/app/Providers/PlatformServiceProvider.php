<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        RateLimiter::for('public-catalog', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
        // Por IP e um teto global: um conjunto grande de IPs não cria negócios sem limite.
        RateLimiter::for('signup', fn (Request $request) => [
            Limit::perMinute(10)->by((string) $request->ip()),
            Limit::perHour(300)->by('signup-global'),
        ]);
        RateLimiter::for('slug-check', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
        RateLimiter::for('platform-login', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('platform-verify', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }
}
