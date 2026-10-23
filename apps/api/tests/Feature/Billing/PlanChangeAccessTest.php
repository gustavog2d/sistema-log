<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

const PLAN_ROUTES = [
    ['GET', 'subscription/plan-change/preview?plan=estudio', []],
    ['PUT', 'subscription/plan', ['plan' => 'estudio']],
    ['DELETE', 'subscription/plan-change', []],
];

it('CA-01 refuses every role but the owner', function (string $role) {
    $this->workspace('neg', 'dona@exemplo.com');
    $this->workspace('neg3', 'papel@exemplo.com', $role);
    $token = $this->tokenFor('neg3', 'papel@exemplo.com');

    foreach (PLAN_ROUTES as [$method, $path, $body]) {
        manage($this, $token, $method, 'neg3', $path, $body, 'k-'.$path)->assertForbidden();
    }
})->with(['manager', 'professional', 'reception']);

it('CA-01 asks for a login and keeps the support access out', function () {
    $this->workspace('neg');

    foreach (PLAN_ROUTES as [$method, $path, $body]) {
        $this->withHeaders(['Idempotency-Key' => 'k'])->json($method, "http://neg.app.test/api/v1/{$path}", $body)->assertUnauthorized();
    }

    foreach (['subscription/plan', 'subscription/plan-change', 'subscription/plan-change/preview'] as $uri) {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/'.$uri);
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->toContain('no.impersonation', 'owner');
    }
});

it('CA-02 refuses a missing, inactive or current plan and changes nothing', function () {
    [$ws, $token] = planOwner($this);
    DB::connection('pgsql_admin')->table('plans')->where('key', 'essencial')->update(['status' => 'draft']);

    foreach (['nao-existe', 'essencial', 'profissional'] as $plan) {
        changePlan($this, $token, 'neg', $plan)->assertStatus(422)->assertJsonValidationErrors('plan');
    }

    manage($this, $token, 'PUT', 'neg', 'subscription/plan', [], 'k2')->assertStatus(422)->assertJsonValidationErrors('plan');
    expect(planKeyOf($ws))->toBe('profissional');
});

it('CA-12 refuses a suspended, canceled or cancel-pending subscription', function (string $status, array $extra, int $writeStatus, string $code) {
    [$ws, $token] = planOwner($this, 'neg', $status, $extra);

    // Suspensa e cancelada: o bloqueio de escrita barra o PUT (403); a simulação (GET) chega ao serviço e dá 409.
    changePlan($this, $token, 'neg', 'estudio')->assertStatus($writeStatus)->assertJsonPath('code', $code);
    manage($this, $token, 'GET', 'neg', 'subscription/plan-change/preview?plan=estudio')->assertStatus(409)->assertJsonPath('code', $code);
    expect(planKeyOf($ws))->toBe('profissional');
})->with([
    'suspended' => ['suspended', ['suspended_at' => '2026-11-01 00:00:00'], 403, 'subscription_suspended'],
    'canceled' => ['canceled', [], 403, 'subscription_canceled'],
    'cancel pending' => ['active', ['canceled_at' => '2026-11-05 00:00:00'], 409, 'cancel_pending'],
]);

it('CA-18 does not let the token of one business change the plan of another', function () {
    [, $tokenA] = planOwner($this, 'negocioa');
    [$b] = planOwner($this, 'negociob');

    changePlan($this, $tokenA, 'negociob', 'estudio')->assertUnauthorized();
    expect(planKeyOf($b))->toBe('profissional');
});
