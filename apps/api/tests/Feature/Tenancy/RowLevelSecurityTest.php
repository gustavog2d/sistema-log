<?php

use App\Tenancy\TenantDatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->seedProbe($this->a, 'a-1');
    $this->seedProbe($this->a, 'a-2');
    $this->seedProbe($this->b, 'b-1');
});

function inContext(string $tenantId): void
{
    app(TenantDatabaseContext::class)->apply($tenantId);
}

it('CA-06 shows only the rows of the tenant in context', function () {
    inContext($this->a->id);

    expect(DB::table('probe_items')->pluck('name')->sort()->values()->all())->toBe(['a-1', 'a-2']);
});

it('CA-07 rejects an insert that carries another tenant id', function () {
    inContext($this->a->id);

    expect(fn () => DB::table('probe_items')->insert(['tenant_id' => $this->b->id, 'name' => 'invasor']))
        ->toThrow(QueryException::class);
});

it('CA-08 does not update or delete rows of another tenant', function () {
    inContext($this->a->id);

    expect(DB::table('probe_items')->where('name', 'b-1')->update(['name' => 'hack']))->toBe(0);
    expect(DB::table('probe_items')->where('name', 'b-1')->delete())->toBe(0);
    expect(DB::connection('pgsql_admin')->table('probe_items')->where('name', 'b-1')->count())->toBe(1);
});

it('CA-09 shows no rows without a tenant in context', function () {
    expect(DB::table('probe_items')->count())->toBe(0);
});

it('CA-10 runs the api connection with a role that cannot bypass rls', function () {
    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

    expect($role->rolsuper)->toBeFalse();
    expect($role->rolbypassrls)->toBeFalse();
});

it('CA-16 lets the runtime role read tenants but not write them', function () {
    expect(DB::table('tenants')->count())->toBe(2);

    expect(fn () => DB::table('tenants')->where('slug', 'beta')->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('tenants')->where('slug', 'beta')->update(['slug' => 'sequestrado']))->toThrow(QueryException::class);
    expect(fn () => DB::table('tenants')->insert(['id' => (string) Str::uuid(), 'name' => 'x', 'slug' => 'novo']))->toThrow(QueryException::class);
});
