<?php

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;

/**
 * @return list<string> URIs de /api/v1 que não declaram tenant, platform nem public
 */
function undeclaredApiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route) => str_starts_with($route->uri(), 'api/v1'))
        ->reject(fn (LaravelRoute $route) => array_intersect(['tenant', 'platform', 'public'], $route->gatherMiddleware()) !== [])
        ->map(fn (LaravelRoute $route) => $route->uri())
        ->values()
        ->all();
}

it('CA-05 declares an isolation group for every api route', function () {
    expect(undeclaredApiRoutes())->toBe([]);
});

it('CA-05 flags a route that has no isolation group', function () {
    Route::get('api/v1/esquecida', fn () => []);

    expect(undeclaredApiRoutes())->toBe(['api/v1/esquecida']);
});
