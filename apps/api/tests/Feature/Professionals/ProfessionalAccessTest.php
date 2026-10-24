<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

/** @return list<array{0: string, 1: string}> */
function professionalWrites(object $professional, object $timeOff): array
{
    return [
        ['POST', 'professionals'], ['PATCH', "professionals/{$professional->id}"], ['DELETE', "professionals/{$professional->id}"],
        ['PUT', "professionals/{$professional->id}/working-hours"],
        ['POST', 'time-offs'], ['PATCH', "time-offs/{$timeOff->id}"], ['DELETE', "time-offs/{$timeOff->id}"],
    ];
}

it('CA-03 lets every role read and only owner and manager write', function (string $role, bool $canWrite) {
    $this->workspace('neg', 'dona@exemplo.com');
    $ws = $this->workspace('neg2', 'papel@exemplo.com', $role);
    $token = $this->tokenFor('neg2', 'papel@exemplo.com');
    $professional = seedProfessional($ws);
    $timeOff = seedTimeOff($ws);

    profApi($this, $token, 'GET', 'neg2')->assertOk();
    profApi($this, $token, 'GET', 'neg2', "professionals/{$professional->id}")->assertOk();
    profApi($this, $token, 'GET', 'neg2', "professionals/{$professional->id}/working-hours")->assertOk();
    profApi($this, $token, 'GET', 'neg2', 'time-offs')->assertOk();

    foreach (professionalWrites($professional, $timeOff) as [$method, $path]) {
        $response = profApi($this, $token, $method, 'neg2', $path, profPayload(['ranges' => []]) + timeOffPayload());
        // Dona e gerente passam da autorização (o pedido pode falhar na validação); os demais papéis são barrados com o código da regra.
        $canWrite ? expect($response->status())->toBeIn([200, 201, 204, 404, 422]) : $response->assertForbidden()->assertJsonPath('title', 'Forbidden');
    }
})->with([['owner', true], ['manager', true], ['professional', false], ['reception', false]]);

it('CA-03 asks for a login on every route', function () {
    [$ws] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);
    $timeOff = seedTimeOff($ws);

    foreach (array_merge([['GET', 'professionals'], ['GET', 'time-offs'], ['GET', "professionals/{$professional->id}"], ['GET', "professionals/{$professional->id}/working-hours"]], professionalWrites($professional, $timeOff)) as [$method, $path]) {
        $this->json($method, "http://neg.app.test/api/v1/{$path}", profPayload())->assertUnauthorized();
    }
});

it('CA-03 gives the support access read-only', function () {
    [$ws] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);
    $timeOff = seedTimeOff($ws);
    $token = supportAccessToken($ws);

    profApi($this, $token, 'GET', 'neg')->assertOk();
    profApi($this, $token, 'GET', 'neg', 'time-offs')->assertOk();

    foreach (professionalWrites($professional, $timeOff) as [$method, $path]) {
        profApi($this, $token, $method, 'neg', $path, profPayload(['ranges' => []]) + timeOffPayload())->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }
    expect(DB::connection('pgsql_admin')->table('professionals')->count())->toBe(1);
});

it('CA-03 keeps reading but blocks writes when the subscription is suspended or canceled', function (string $status, string $code) {
    [$ws, $token] = ownerOf($this, 'neg', $status, $status === 'suspended' ? ['suspended_at' => now()->subDay()] : []);
    $professional = seedProfessional($ws);
    $timeOff = seedTimeOff($ws);

    profApi($this, $token, 'GET', 'neg')->assertOk();
    profApi($this, $token, 'GET', 'neg', 'time-offs')->assertOk();
    foreach (professionalWrites($professional, $timeOff) as [$method, $path]) {
        profApi($this, $token, $method, 'neg', $path, profPayload(['ranges' => []]) + timeOffPayload())->assertForbidden()->assertJsonPath('code', $code);
    }
})->with([['suspended', 'subscription_suspended'], ['canceled', 'subscription_canceled']]);

it('CA-09 does not let the token of one business reach another', function () {
    [, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    seedProfessional($b);

    profApi($this, $tokenA, 'GET', 'negociob')->assertUnauthorized();
});
