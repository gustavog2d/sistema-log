<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('CA-01 resolves the tenant from the subdomain', function () {
    $acme = $this->makeTenant('acme');
    $this->makeTenant('beta');

    $this->getJson('http://acme.app.test/api/v1/tenant/current')
        ->assertOk()
        ->assertJson(['id' => $acme->id, 'slug' => 'acme', 'name' => 'Acme']);
});

it('CA-02 returns problem details for an unknown subdomain', function () {
    $this->makeTenant('acme');

    $this->getJson('http://fantasma.app.test/api/v1/tenant/current')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['type', 'title', 'status']);
});

it('CA-03 rejects tenant routes on the platform host', function () {
    $this->getJson('http://app.test/api/v1/tenant/current')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->getJson('http://admin.app.test/api/v1/tenant/current')
        ->assertNotFound();
});

it('CA-04 rejects platform routes on a tenant host', function () {
    Route::middleware('platform')->get('api/v1/_probe/platform', fn () => ['ok' => true]);
    $this->makeTenant('acme');

    $this->getJson('http://acme.app.test/api/v1/_probe/platform')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->getJson('http://app.test/api/v1/_probe/platform')->assertOk();
});

it('RN-03 returns a body that matches the openapi contract', function () {
    $this->makeTenant('acme');
    $schema = data_get(
        Yaml::parseFile(base_path('../../docs/funcionalidades/FND-005-base-multi-tenant/openapi.yaml')),
        'components.schemas.Tenant.properties',
    );

    $body = $this->getJson('http://acme.app.test/api/v1/tenant/current')->assertOk()->json();

    expect(array_map('strval', array_keys($body)))->toEqualCanonicalizing(array_map('strval', array_keys($schema)));
});

it('RN-05 keeps resolving a suspended tenant', function () {
    $tenant = $this->makeTenant('acme');
    $tenant->update(['status' => 'suspended']);

    $this->getJson('http://acme.app.test/api/v1/tenant/current')->assertOk()->assertJson(['slug' => 'acme']);
});

it('RN-03 ignores the case of the host', function () {
    $this->makeTenant('acme');

    $this->getJson('http://ACME.app.test/api/v1/tenant/current')->assertOk()->assertJson(['slug' => 'acme']);
});

it('RN-03 does not resolve nested or foreign hosts', function (string $host) {
    $this->makeTenant('acme');

    $this->getJson("http://{$host}/api/v1/tenant/current")->assertNotFound();
})->with(['x.acme.app.test', 'acme.evil.com', 'acme.app.test.evil.com', 'evil.com']);

it('RN-02 does not resolve a reserved slug even if the row exists', function () {
    $this->makeTenant('www');

    $this->getJson('http://www.app.test/api/v1/tenant/current')->assertNotFound();
});

it('RN-02 enforces the slug format in the database', function () {
    expect(fn () => $this->makeTenant('Slug Invalido'))->toThrow(QueryException::class);
});
