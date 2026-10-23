<?php

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

it('CA-14 returns bodies that respect the openapi contract', function () {
    $this->loadOpenApi('PLT-009-gestao-de-assinantes');
    $ws = adminTenant($this, 'neg', 'trialing', ['trial_ends_at' => now()->addDays(3)]);
    $admin = $this->adminToken();

    $list = adm($this, $admin, 'GET', 'tenants')->assertOk();
    expect($this->schemaErrors($list->json(), 'TenantPage'))->toBe([]);

    $detail = adm($this, $admin, 'GET', "tenants/{$ws->id}")->assertOk();
    expect($this->schemaErrors($detail->json(), 'TenantDetail'))->toBe([]);

    foreach ([['POST', 'extend-trial', ['days' => 2]], ['POST', 'suspend', ['reason' => 'Motivo qualquer']], ['POST', 'unsuspend', []]] as [$method, $path, $body]) {
        expect($this->schemaErrors(adm($this, $admin, $method, "tenants/{$ws->id}/{$path}", $body)->assertOk()->json(), 'TenantItem'))->toBe([]);
    }

    expect($this->schemaErrors(adm($this, $admin, 'PUT', "tenants/{$ws->id}/entitlements/usuarios.max", ['enabled' => true, 'limit' => 3])->assertOk()->json(), 'Entitlement'))->toBe([])
        ->and($this->schemaErrors(adm($this, $admin, 'DELETE', "tenants/{$ws->id}/entitlements/usuarios.max")->assertOk()->json(), 'Entitlement'))->toBe([]);

    Mail::fake();
    $impersonation = adm($this, $admin, 'POST', "tenants/{$ws->id}/impersonate", ['reason' => 'Verificar o contrato'])->assertOk();
    expect($this->schemaErrors($impersonation->json(), 'Impersonation'))->toBe([]);

    $problem = adm($this, $admin, 'GET', 'tenants/'.Str::uuid())->assertNotFound();
    expect($this->schemaErrors($problem->json(), 'Problem'))->toBe([]);
});

it('CA-14 documents every route that exists', function () {
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-009-gestao-de-assinantes/openapi.yaml'));
    $normalize = fn (string $uri) => preg_replace('/\{[^}]+\}/', '{x}', $uri);
    $declared = collect($spec['paths'])->keys()->map(fn ($path) => $normalize('api/v1'.$path))->sort()->values()->all();
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $normalize($route->uri()))
        ->filter(fn ($uri) => str_starts_with($uri, 'api/v1/platform/tenants'))->unique()->sort()->values()->all();

    expect($routes)->toBe($declared);
});
