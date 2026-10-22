<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('resolves the tenant from the subdomain', function () {
    $acme = $this->makeTenant('acme');
    $this->makeTenant('beta');

    $this->getJson('http://acme.app.test/api/v1/tenant/current')
        ->assertOk()
        ->assertJson(['id' => $acme->id, 'slug' => 'acme', 'name' => 'Acme']);
});

it('returns problem details for an unknown subdomain', function () {
    $this->makeTenant('acme');

    $this->getJson('http://fantasma.app.test/api/v1/tenant/current')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['type', 'title', 'status']);
});

it('rejects tenant routes on the platform host', function () {
    $this->getJson('http://app.test/api/v1/tenant/current')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->getJson('http://admin.app.test/api/v1/tenant/current')
        ->assertNotFound();
});

it('rejects platform routes on a tenant host', function () {
    Route::middleware('platform')->get('api/v1/_probe/platform', fn () => ['ok' => true]);
    $this->makeTenant('acme');

    $this->getJson('http://acme.app.test/api/v1/_probe/platform')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->getJson('http://app.test/api/v1/_probe/platform')->assertOk();
});

it('returns a body that matches the openapi contract', function () {
    $this->makeTenant('acme');
    $schema = data_get(
        Yaml::parseFile(base_path('../../docs/funcionalidades/FND-005-base-multi-tenant/openapi.yaml')),
        'components.schemas.Tenant.properties',
    );

    $body = $this->getJson('http://acme.app.test/api/v1/tenant/current')->assertOk()->json();

    expect(array_map('strval', array_keys($body)))->toEqualCanonicalizing(array_map('strval', array_keys($schema)));
});
