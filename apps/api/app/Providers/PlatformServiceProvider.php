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
        RateLimiter::for('platform-login', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('platform-verify', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));
    }
}
