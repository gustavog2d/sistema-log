<?php

use App\Clients\ClientService;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-05 creates a client with name and phone and says it is new', function () {
    [, $token] = ownerOf($this, 'neg');

    clientsApi($this, $token, 'POST', 'neg', 'clients/quick', ['name' => 'Bia', 'phone' => '+55 11 99999-8888'])->assertCreated()
        ->assertJsonPath('existing', false)->assertJsonPath('client.phone', '+5511999998888')->assertJsonPath('client.name', 'Bia');
});

it('CA-05 returns the existing client when the phone is already registered', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $existing = seedClient($ws, ['name' => 'Beatriz', 'phone' => '+5511999998888']);

    clientsApi($this, $token, 'POST', 'neg', 'clients/quick', ['name' => 'Bia', 'phone' => '(11) 99999-8888', 'country' => '55'])->assertStatus(422);
    clientsApi($this, $token, 'POST', 'neg', 'clients/quick', ['name' => 'Bia', 'phone' => '+55 (11) 99999-8888'])->assertOk()
        ->assertJsonPath('existing', true)->assertJsonPath('client.id', $existing->id)->assertJsonPath('client.name', 'Beatriz');

    expect(DB::connection('pgsql_admin')->table('clients')->count())->toBe(1);
});

it('CA-05 validates the quick creation like the normal one', function (array $payload, string $field) {
    [, $token] = ownerOf($this, 'neg');

    clientsApi($this, $token, 'POST', 'neg', 'clients/quick', $payload + clientPayload())->assertStatus(422)->assertJsonValidationErrors($field);
})->with([[['name' => 'A'], 'name'], [['phone' => '123'], 'phone'], [['name' => 'ver https://x.com'], 'name']]);

it('CA-05 keeps a single client when the same phone is created twice, deciding by the database', function () {
    [$ws] = ownerOf($this, 'neg');
    app(TenantContext::class)->set(Tenant::query()->findOrFail($ws->tenant_id));
    app(TenantDatabaseContext::class)->apply($ws->tenant_id);
    $service = app(ClientService::class);

    $first = $service->quick('Bia', '+5511999998888');
    $second = $service->quick('Outra Bia', '+5511999998888');

    expect($first['existing'])->toBeFalse()->and($second['existing'])->toBeTrue()->and($second['client']->id)->toBe($first['client']->id)
        ->and(DB::connection('pgsql_admin')->table('clients')->count())->toBe(1)
        ->and(auditRows())->toHaveCount(1);
});

it('CA-07 records creation, edition and deletion in the history with before and after', function () {
    [, $token] = ownerOf($this, 'neg');

    $id = clientsApi($this, $token, 'POST', 'neg', 'clients', clientPayload(['email' => 'bia@exemplo.com', 'notes' => 'nota']))->assertCreated()->json('id');
    clientsApi($this, $token, 'PATCH', 'neg', "clients/{$id}", ['name' => 'Beatriz', 'notes' => 'nota'])->assertOk();
    clientsApi($this, $token, 'PATCH', 'neg', "clients/{$id}", ['name' => 'Beatriz'])->assertOk();
    clientsApi($this, $token, 'DELETE', 'neg', "clients/{$id}")->assertNoContent();

    $rows = auditRows();
    expect($rows)->toHaveCount(3)
        ->and($rows[0]->action)->toBe('created')->and($rows[0]->entity_type)->toBe('client')->and($rows[0]->after['phone'])->toBe('+5511999998888')
        ->and($rows[0]->actor_name)->not->toBeNull()
        ->and($rows[1]->action)->toBe('updated')->and($rows[1]->before)->toBe(['name' => 'Bia Lima'])->and($rows[1]->after)->toBe(['name' => 'Beatriz'])
        ->and($rows[2]->action)->toBe('deleted')->and($rows[2]->before['name'])->toBe('Beatriz');
});

it('CA-07 shows the history of a client through the audit api', function () {
    [, $token] = ownerOf($this, 'neg');
    $id = clientsApi($this, $token, 'POST', 'neg', 'clients', clientPayload())->assertCreated()->json('id');

    $logs = clientsApi($this, $token, 'GET', 'neg', "audit-logs?entity_type=client&entity_id={$id}")->assertOk()->json('data');

    expect($logs)->toHaveCount(1)->and($logs[0]['action'])->toBe('created');
});
