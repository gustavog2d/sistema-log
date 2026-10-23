<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-03 lets the four roles read, create and edit, and only owner and manager delete', function (string $role, bool $canDelete) {
    $this->workspace('neg', 'dona@exemplo.com');
    $ws = $this->workspace('neg2', 'papel@exemplo.com', $role);
    $token = $this->tokenFor('neg2', 'papel@exemplo.com');
    $client = seedClient($ws);

    clientsApi($this, $token, 'GET', 'neg2')->assertOk();
    $created = clientsApi($this, $token, 'POST', 'neg2', 'clients', clientPayload())->assertCreated();
    clientsApi($this, $token, 'PATCH', 'neg2', 'clients/'.$created->json('id'), ['name' => 'Outro nome'])->assertOk();
    clientsApi($this, $token, 'POST', 'neg2', 'clients/quick', ['name' => 'Rápido', 'phone' => '+5511555554444'])->assertCreated();
    clientsApi($this, $token, 'DELETE', 'neg2', "clients/{$client->id}")->assertStatus($canDelete ? 204 : 403);
})->with([['owner', true], ['manager', true], ['professional', false], ['reception', false]]);

it('CA-03 asks for a login on every route', function () {
    [$ws] = ownerOf($this, 'neg');
    $client = seedClient($ws);

    foreach ([['GET', 'clients'], ['POST', 'clients'], ['POST', 'clients/quick'], ['GET', "clients/{$client->id}"], ['PATCH', "clients/{$client->id}"], ['DELETE', "clients/{$client->id}"]] as [$method, $path]) {
        $this->json($method, "http://neg.app.test/api/v1/{$path}", clientPayload())->assertUnauthorized();
    }
});

it('CA-03 gives the support access read-only', function () {
    [$ws] = ownerOf($this, 'neg');
    $client = seedClient($ws);
    $token = supportAccessToken($ws);

    clientsApi($this, $token, 'GET', 'neg')->assertOk();
    clientsApi($this, $token, 'GET', 'neg', "clients/{$client->id}")->assertOk();

    foreach ([['POST', 'clients'], ['POST', 'clients/quick'], ['PATCH', "clients/{$client->id}"], ['DELETE', "clients/{$client->id}"]] as [$method, $path]) {
        clientsApi($this, $token, $method, 'neg', $path, clientPayload())->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }
    expect(DB::connection('pgsql_admin')->table('clients')->count())->toBe(1);
});

it('CA-08 keeps the clients readable but blocks writes when the subscription is suspended or canceled', function (string $status, string $code) {
    [$ws, $token] = ownerOf($this, 'neg', $status, $status === 'suspended' ? ['suspended_at' => now()->subDay()] : []);
    $client = seedClient($ws);

    clientsApi($this, $token, 'GET', 'neg')->assertOk();
    clientsApi($this, $token, 'GET', 'neg', "clients/{$client->id}")->assertOk();
    foreach ([['POST', 'clients'], ['POST', 'clients/quick'], ['PATCH', "clients/{$client->id}"], ['DELETE', "clients/{$client->id}"]] as [$method, $path]) {
        clientsApi($this, $token, $method, 'neg', $path, clientPayload())->assertForbidden()->assertJsonPath('code', $code);
    }
})->with([['suspended', 'subscription_suspended'], ['canceled', 'subscription_canceled']]);

it('CA-03 does not let the token of one business reach the clients of another', function () {
    [, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    seedClient($b);

    clientsApi($this, $tokenA, 'GET', 'negociob')->assertUnauthorized();
});
