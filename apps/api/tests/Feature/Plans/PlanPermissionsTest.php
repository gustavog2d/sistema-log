<?php

use Database\Factories\FeatureFactory;
use Database\Factories\PlanFactory;

beforeEach(function () {
    $this->plan = PlanFactory::new()->create();
    $this->feature = FeatureFactory::new()->create();
});

it('CA-11 lets support read plans, features and entitlements', function () {
    $token = $this->supportToken();

    $this->asToken($token, 'GET', 'plans')->assertOk();
    $this->asToken($token, 'GET', "plans/{$this->plan->id}")->assertOk();
    $this->asToken($token, 'GET', "plans/{$this->plan->id}/entitlements")->assertOk();
    $this->asToken($token, 'GET', 'features')->assertOk();
});

it('CA-11 forbids support from changing the catalog', function (string $method, string $path, array $payload) {
    $this->asToken($this->supportToken(), $method, str_replace(['{plan}', '{feature}'], [$this->plan->id, $this->feature->id], $path), $payload)
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json');
})->with([
    ['POST', 'plans', ['key' => 'x', 'name' => 'X', 'position' => 1]],
    ['PATCH', 'plans/{plan}', ['name' => 'Y']],
    ['PUT', 'plans/{plan}/features/{feature}', ['enabled' => true, 'visible' => true]],
    ['DELETE', 'plans/{plan}/features/{feature}', []],
    ['POST', 'features', ['key' => 'x.y', 'name' => 'X', 'kind' => 'toggle', 'default_enabled' => true, 'default_visible' => true]],
    ['PATCH', 'features/{feature}', ['name' => 'Y']],
]);

it('CA-16 requires authentication on every management route', function (string $method, string $path) {
    $this->app['auth']->forgetGuards();

    $this->json($method, $this->platformUrl(str_replace(['{plan}', '{feature}'], [$this->plan->id, $this->feature->id], $path)))
        ->assertUnauthorized();
})->with([
    ['GET', 'plans'], ['POST', 'plans'], ['GET', 'plans/{plan}'], ['PATCH', 'plans/{plan}'],
    ['GET', 'plans/{plan}/entitlements'], ['PUT', 'plans/{plan}/features/{feature}'],
    ['DELETE', 'plans/{plan}/features/{feature}'], ['GET', 'features'], ['POST', 'features'], ['PATCH', 'features/{feature}'],
]);

it('CA-16 rejects a challenge token on management routes', function () {
    $user = $this->platformUser();
    $challenge = $this->login($user->email)->json('challenge_token');

    $this->asToken($challenge, 'GET', 'plans')->assertForbidden();
});

it('hides the management routes on a tenant host', function () {
    $this->makeTenant('acme');

    $this->getJson('http://acme.app.test/api/v1/platform/plans')->assertNotFound();
});
