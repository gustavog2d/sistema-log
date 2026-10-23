<?php

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-10 returns bodies that respect the openapi contract', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $this->loadOpenApi('CLI-001-clientes');

    $created = clientsApi($this, $token, 'POST', 'neg', 'clients', clientPayload(['email' => 'bia@exemplo.com', 'instagram' => 'bia', 'notes' => 'n']))->assertCreated();
    expect($this->schemaErrors($created->json(), 'Client'))->toBe([]);

    $show = clientsApi($this, $token, 'GET', 'neg', 'clients/'.$created->json('id'))->assertOk();
    expect($this->schemaErrors($show->json(), 'Client'))->toBe([]);

    $patched = clientsApi($this, $token, 'PATCH', 'neg', 'clients/'.$created->json('id'), ['name' => 'Beatriz'])->assertOk();
    expect($this->schemaErrors($patched->json(), 'Client'))->toBe([]);

    $list = clientsApi($this, $token, 'GET', 'neg')->assertOk();
    expect($this->schemaErrors($list->json(), 'ClientPage'))->toBe([]);

    $quickNew = clientsApi($this, $token, 'POST', 'neg', 'clients/quick', ['name' => 'Nova', 'phone' => '+5511777776666'])->assertCreated();
    expect($this->schemaErrors($quickNew->json(), 'QuickClient'))->toBe([]);
    $quickOld = clientsApi($this, $token, 'POST', 'neg', 'clients/quick', ['name' => 'Nova', 'phone' => '+5511777776666'])->assertOk();
    expect($this->schemaErrors($quickOld->json(), 'QuickClient'))->toBe([]);
});

it('CA-10 returns the documented problem body for a validation error', function () {
    [, $token] = ownerOf($this, 'neg');
    $this->loadOpenApi('CLI-001-clientes');

    $problem = clientsApi($this, $token, 'POST', 'neg', 'clients', ['name' => 'A', 'phone' => '1'])->assertStatus(422);

    expect($this->schemaErrors($problem->json(), 'Problem'))->toBe([]);
});
