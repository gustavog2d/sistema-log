<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function entitlement($test, string $token, object $ws, string $feature, array $body)
{
    return adm($test, $token, 'PUT', "tenants/{$ws->id}/entitlements/{$feature}", $body);
}

it('CA-03 creates an exception that wins over the plan and removes it', function () {
    $ws = adminTenant($this);
    $admin = $this->adminToken();

    entitlement($this, $admin, $ws, 'usuarios.max', ['enabled' => true, 'limit' => 7, 'visible' => true])
        ->assertOk()->assertJsonPath('source', 'override')->assertJsonPath('limit', 7);

    $detail = adm($this, $admin, 'GET', "tenants/{$ws->id}")->json('entitlements');
    expect(collect($detail)->firstWhere('feature', 'usuarios.max')['limit'])->toBe(7);

    adm($this, $admin, 'DELETE', "tenants/{$ws->id}/entitlements/usuarios.max")->assertOk()->assertJsonPath('source', fn ($source) => $source !== 'override');
    expect(DB::connection('pgsql_admin')->table('tenant_entitlement_overrides')->count())->toBe(0);
});

it('CA-03 replaces the same exception instead of duplicating it', function () {
    $ws = adminTenant($this);

    entitlement($this, $this->adminToken(), $ws, 'usuarios.max', ['enabled' => true, 'limit' => 3])->assertOk();
    entitlement($this, $this->adminToken(), $ws, 'usuarios.max', ['enabled' => true, 'limit' => 9])->assertOk();

    expect(DB::connection('pgsql_admin')->table('tenant_entitlement_overrides')->count())->toBe(1)
        ->and(DB::connection('pgsql_admin')->table('tenant_entitlement_overrides')->value('limit'))->toBe(9);
});

it('CA-03 affects what reads the resolver, like the team limit', function () {
    $ws = adminTenant($this);
    DB::connection('pgsql_admin')->table('features')->where('key', 'usuarios.max')->update(['default_enabled' => true, 'default_limit' => 1]);
    $admin = $this->adminToken();
    entitlement($this, $admin, $ws, 'usuarios.max', ['enabled' => true, 'limit' => 1]);

    $impersonation = adm($this, $this->supportToken(), 'POST', "tenants/{$ws->id}/impersonate", ['reason' => 'Ajudar a dona a convidar gente'])->assertOk();
    $token = $impersonation->json('access_token');

    asTenant($this, $token, 'POST', 'neg', 'users/invitations', ['name' => 'Nova Pessoa', 'email' => 'nova@exemplo.com', 'role' => 'reception'])->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');

    entitlement($this, $this->adminToken(), $ws, 'usuarios.max', ['enabled' => true, 'limit' => 5])->assertOk();
    asTenant($this, $token, 'POST', 'neg', 'users/invitations', ['name' => 'Nova Pessoa', 'email' => 'nova@exemplo.com', 'role' => 'reception'])->assertCreated();
});

it('CA-04 refuses invalid exceptions', function (string $feature, array $body) {
    $ws = adminTenant($this);

    entitlement($this, $this->adminToken(), $ws, $feature, $body)->assertUnprocessable();
})->with([
    'negative limit' => ['usuarios.max', ['enabled' => true, 'limit' => -1]],
    'limit on a toggle' => ['orcamento', ['enabled' => true, 'limit' => 5]],
    'missing enabled' => ['usuarios.max', ['limit' => 5]],
]);

it('answers 404 for an unknown feature or tenant', function () {
    $ws = adminTenant($this);

    entitlement($this, $this->adminToken(), $ws, 'nao.existe', ['enabled' => true])->assertNotFound();
    adm($this, $this->adminToken(), 'PUT', 'tenants/'.Str::uuid().'/entitlements/usuarios.max', ['enabled' => true])->assertNotFound();
});

it('CA-12 only lets the super admin change exceptions', function () {
    $ws = adminTenant($this);

    entitlement($this, $this->supportToken(), $ws, 'usuarios.max', ['enabled' => true, 'limit' => 5])->assertForbidden();
    adm($this, $this->supportToken(), 'DELETE', "tenants/{$ws->id}/entitlements/usuarios.max")->assertForbidden();
});

it('CA-13 audits the exception with before and after', function () {
    $ws = adminTenant($this);

    entitlement($this, $this->adminToken(), $ws, 'usuarios.max', ['enabled' => true, 'limit' => 7])->assertOk();
    entitlement($this, $this->adminToken(), $ws, 'usuarios.max', ['enabled' => true, 'limit' => 8])->assertOk();

    $logs = DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'tenant.entitlement_set')->get();
    expect($logs)->toHaveCount(2)->and($logs->last()->metadata)->toContain('"before"')->toContain('"after"');
});
