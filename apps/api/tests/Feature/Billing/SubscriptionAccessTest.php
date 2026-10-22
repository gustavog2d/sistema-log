<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seedBillingCatalog();
    Route::middleware(['tenant', 'subscription.access'])->group(function () {
        Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], 'api/v1/_probe/data', fn () => ['ok' => true]);
    });
    Route::middleware('platform')->post('api/v1/_probe/platform-write', fn () => ['ok' => true]);
    Route::middleware('public')->post('api/v1/_probe/public-write', fn () => ['ok' => true]);
});

function writeProbe($test, string $method = 'POST')
{
    return $test->json($method, 'http://neg.app.test/api/v1/_probe/data', []);
}

it('CA-01 allows writes while the subscription is in trial or active', function (string $status) {
    $this->subscription(['status' => $status], 'pm_fake_ok_x', 'neg');

    foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
        writeProbe($this, $method)->assertOk();
    }
})->with(['trialing', 'active']);

it('CA-02 refuses every write for a suspended subscription with a machine readable code', function (string $method) {
    $this->subscription(['status' => 'suspended'], 'pm_fake_ok_x', 'neg');

    writeProbe($this, $method)
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['status' => 403, 'code' => 'subscription_suspended']);
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('CA-03 keeps reading allowed for a suspended subscription', function () {
    $this->subscription(['status' => 'suspended'], 'pm_fake_ok_x', 'neg');

    writeProbe($this, 'GET')->assertOk();
    $this->call('HEAD', 'http://neg.app.test/api/v1/_probe/data')->assertOk();
});

it('CA-04 treats a canceled subscription as read only, with its own code', function () {
    $this->subscription(['status' => 'canceled'], 'pm_fake_ok_x', 'neg');

    writeProbe($this)->assertForbidden()->assertJson(['code' => 'subscription_canceled']);
    writeProbe($this, 'GET')->assertOk();
});

it('CA-05 treats a business without subscription as suspended', function () {
    $this->makeTenant('neg');

    writeProbe($this)->assertForbidden()->assertJson(['code' => 'subscription_suspended']);
    writeProbe($this, 'GET')->assertOk();
});

it('CA-06 blocks right after the suspension and releases right after the regularization, with no cache', function () {
    $sub = $this->subscription(['status' => 'active'], 'pm_fake_ok_x', 'neg');
    writeProbe($this)->assertOk();

    DB::connection('pgsql_admin')->table('subscriptions')->where('id', $sub->id)->update(['status' => 'suspended']);
    writeProbe($this)->assertForbidden();

    DB::connection('pgsql_admin')->table('subscriptions')->where('id', $sub->id)->update(['status' => 'active']);
    writeProbe($this)->assertOk();
});

it('CA-10 does not affect platform or public routes when a business is suspended', function () {
    $this->subscription(['status' => 'suspended'], 'pm_fake_ok_x', 'neg');

    $this->postJson('http://app.test/api/v1/_probe/platform-write')->assertOk();
    $this->postJson('http://app.test/api/v1/_probe/public-write')->assertOk();
    $this->getJson('http://app.test/api/v1/plans')->assertOk();
});

it('only looks at the subscription of the business of the host', function () {
    $this->subscription(['status' => 'suspended'], 'pm_fake_ok_x', 'neg');
    $this->subscription(['status' => 'active'], 'pm_fake_ok_x', 'outro');

    writeProbe($this)->assertForbidden();
    $this->postJson('http://outro.app.test/api/v1/_probe/data')->assertOk();
});

it('A1 applies subscription.access to every non read route of the real tenant group', function () {
    $offenders = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1') && in_array('tenant', $route->gatherMiddleware(), true))
        ->filter(fn ($route) => array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [])
        ->reject(fn ($route) => in_array('subscription.access', $route->gatherMiddleware(), true) || in_array('subscription.exempt', $route->gatherMiddleware(), true))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

it('A1 would flag a tenant write route that forgot the middleware', function () {
    Route::middleware('tenant')->post('api/v1/_probe/esquecida', fn () => []);

    $flagged = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => $route->uri() === 'api/v1/_probe/esquecida' && ! in_array('subscription.access', $route->gatherMiddleware(), true));

    expect($flagged)->toHaveCount(1);
});

it('B2 limits the public subscription status per address', function () {
    $this->subscription(['status' => 'active'], 'pm_fake_ok_x', 'neg');

    // O limite conta também os pedidos sem login (o 401 é mais barato, mas continua contando).
    foreach (range(1, 60) as $_) {
        $this->getJson('http://neg.app.test/api/v1/subscription')->assertUnauthorized();
    }

    $this->getJson('http://neg.app.test/api/v1/subscription')->assertStatus(429);
});

it('allows OPTIONS for a suspended subscription', function () {
    $this->subscription(['status' => 'suspended'], 'pm_fake_ok_x', 'neg');

    $this->call('OPTIONS', 'http://neg.app.test/api/v1/_probe/data')->assertOk();
});

it('fails loudly when the middleware is used without the tenant middleware', function () {
    Route::middleware('subscription.access')->post('api/v1/_probe/sem-tenant', fn () => []);

    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('http://neg.app.test/api/v1/_probe/sem-tenant'))->toThrow(LogicException::class);
});

it('A1 lists exactly the tenant routes that are allowed to work with a suspended subscription', function () {
    $exempt = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1') && in_array('subscription.exempt', $route->gatherMiddleware(), true))
        ->map(fn ($route) => $route->uri())->sort()->values()->all();

    expect($exempt)->toBe([
        'api/v1/auth/2fa/confirm', 'api/v1/auth/2fa/disable', 'api/v1/auth/2fa/setup', 'api/v1/auth/2fa/verify',
        'api/v1/auth/login', 'api/v1/auth/logout', 'api/v1/auth/logout-all', 'api/v1/auth/me',
        'api/v1/auth/password/change', 'api/v1/auth/password/forgot', 'api/v1/auth/password/reset',
    ]);
});
