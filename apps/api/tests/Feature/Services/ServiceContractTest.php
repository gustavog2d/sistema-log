<?php

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-11 returns bodies that respect the openapi contract', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    setNiche($ws, 'manicure');
    $this->loadOpenApi('AGD-001-servicos');

    $category = servicesApi($this, $token, 'POST', 'neg', 'service-categories', ['name' => 'Cabelo'])->assertCreated();
    expect($this->schemaErrors($category->json(), 'ServiceCategory'))->toBe([]);

    $renamed = servicesApi($this, $token, 'PATCH', 'neg', 'service-categories/'.$category->json('id'), ['name' => 'Cabelos'])->assertOk();
    expect($this->schemaErrors($renamed->json(), 'ServiceCategory'))->toBe([]);

    $categories = servicesApi($this, $token, 'GET', 'neg', 'service-categories')->assertOk();
    expect($categories->json('data.0'))->not->toBeNull();
    foreach ($categories->json('data') as $item) {
        expect($this->schemaErrors($item, 'ServiceCategory'))->toBe([]);
    }

    $created = servicesApi($this, $token, 'POST', 'neg', 'services', servicePayload(['category_id' => $category->json('id'), 'price_cents' => 5000]))->assertCreated();
    expect($this->schemaErrors($created->json(), 'Service'))->toBe([]);

    $show = servicesApi($this, $token, 'GET', 'neg', 'services/'.$created->json('id'))->assertOk();
    expect($this->schemaErrors($show->json(), 'Service'))->toBe([]);

    $patched = servicesApi($this, $token, 'PATCH', 'neg', 'services/'.$created->json('id'), ['status' => 'archived', 'price_cents' => null])->assertOk();
    expect($this->schemaErrors($patched->json(), 'Service'))->toBe([]);

    $list = servicesApi($this, $token, 'GET', 'neg', 'services?status=all')->assertOk();
    expect($this->schemaErrors($list->json(), 'ServicePage'))->toBe([]);

    $preset = servicesApi($this, $token, 'POST', 'neg', 'services/presets')->assertOk();
    expect($this->schemaErrors($preset->json(), 'PresetResult'))->toBe([]);
});

it('CA-11 returns the documented problem bodies', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $this->loadOpenApi('AGD-001-servicos');
    $category = seedCategory($ws);
    seedService($ws, ['category_id' => $category->id]);

    $invalid = servicesApi($this, $token, 'POST', 'neg', 'services', ['name' => 'A', 'duration_minutes' => 1])->assertStatus(422);
    expect($this->schemaErrors($invalid->json(), 'Problem'))->toBe([]);

    $inUse = servicesApi($this, $token, 'DELETE', 'neg', "service-categories/{$category->id}")->assertStatus(409);
    expect($this->schemaErrors($inUse->json(), 'Problem'))->toBe([]);
});
