<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

/** @return list<array{0: string, 1: string}> rotas que mudam dados */
function serviceWrites(object $service, object $category): array
{
    return [
        ['POST', 'services'], ['PATCH', "services/{$service->id}"], ['DELETE', "services/{$service->id}"], ['POST', 'services/presets'],
        ['POST', 'service-categories'], ['PATCH', "service-categories/{$category->id}"], ['DELETE', "service-categories/{$category->id}"],
    ];
}

it('CA-03 lets every role read and only owner and manager write', function (string $role, bool $canWrite) {
    $this->workspace('neg', 'dona@exemplo.com');
    $ws = $this->workspace('neg2', 'papel@exemplo.com', $role);
    $token = $this->tokenFor('neg2', 'papel@exemplo.com');
    $service = seedService($ws);
    $category = seedCategory($ws);

    servicesApi($this, $token, 'GET', 'neg2')->assertOk();
    servicesApi($this, $token, 'GET', 'neg2', "services/{$service->id}")->assertOk();
    servicesApi($this, $token, 'GET', 'neg2', 'service-categories')->assertOk();

    foreach (serviceWrites($service, $category) as [$method, $path]) {
        $response = servicesApi($this, $token, $method, 'neg2', $path, servicePayload(['name' => 'Novo '.$method.$path]));
        $canWrite ? expect($response->status())->not->toBe(403) : $response->assertForbidden();
    }
})->with([['owner', true], ['manager', true], ['professional', false], ['reception', false]]);

it('CA-03 asks for a login on every route', function () {
    [$ws] = ownerOf($this, 'neg');
    $service = seedService($ws);
    $category = seedCategory($ws);

    foreach (array_merge([['GET', 'services'], ['GET', 'service-categories'], ['GET', "services/{$service->id}"]], serviceWrites($service, $category)) as [$method, $path]) {
        $this->json($method, "http://neg.app.test/api/v1/{$path}", servicePayload())->assertUnauthorized();
    }
});

it('CA-03 gives the support access read-only', function () {
    [$ws] = ownerOf($this, 'neg');
    $service = seedService($ws);
    $category = seedCategory($ws);
    $token = supportAccessToken($ws);

    servicesApi($this, $token, 'GET', 'neg')->assertOk();
    servicesApi($this, $token, 'GET', 'neg', 'service-categories')->assertOk();

    foreach (serviceWrites($service, $category) as [$method, $path]) {
        servicesApi($this, $token, $method, 'neg', $path, servicePayload())->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }
    expect(DB::connection('pgsql_admin')->table('services')->count())->toBe(1);
});

it('CA-03 keeps reading but blocks writes when the subscription is suspended or canceled', function (string $status, string $code) {
    [$ws, $token] = ownerOf($this, 'neg', $status, $status === 'suspended' ? ['suspended_at' => now()->subDay()] : []);
    $service = seedService($ws);
    $category = seedCategory($ws);

    servicesApi($this, $token, 'GET', 'neg')->assertOk();
    servicesApi($this, $token, 'GET', 'neg', 'service-categories')->assertOk();
    foreach (serviceWrites($service, $category) as [$method, $path]) {
        servicesApi($this, $token, $method, 'neg', $path, servicePayload())->assertForbidden()->assertJsonPath('code', $code);
    }
})->with([['suspended', 'subscription_suspended'], ['canceled', 'subscription_canceled']]);

it('CA-09 does not let the token of one business reach the catalog of another', function () {
    [, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    seedService($b);

    servicesApi($this, $tokenA, 'GET', 'negociob')->assertUnauthorized();
});
