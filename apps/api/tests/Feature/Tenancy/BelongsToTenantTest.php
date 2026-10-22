<?php

use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use Tests\Support\ProbeItem;

function enterTenant(object $tenant): void
{
    app(TenantContext::class)->set($tenant);
    app(TenantDatabaseContext::class)->apply($tenant->id);
}

beforeEach(function () {
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
    $this->seedProbe($this->a, 'a-1');
    $this->seedProbe($this->b, 'b-1');
});

it('CA-12 scopes queries to the tenant in context', function () {
    enterTenant($this->a);

    expect(ProbeItem::pluck('name')->all())->toBe(['a-1']);
});

it('CA-12 fills tenant_id when creating', function () {
    enterTenant($this->a);

    $item = ProbeItem::create(['name' => 'novo']);

    expect($item->tenant_id)->toBe($this->a->id);
});

it('CA-13 fails closed on queries without a tenant in context', function () {
    expect(fn () => ProbeItem::all())->toThrow(TenantContextMissing::class);
});

it('CA-13 fails closed on create without a tenant in context', function () {
    expect(fn () => ProbeItem::create(['name' => 'sem-tenant']))->toThrow(TenantContextMissing::class);
});

it('CA-12 refuses a tenant_id different from the tenant in context', function () {
    enterTenant($this->a);

    $item = new ProbeItem(['name' => 'invasor']);
    $item->tenant_id = $this->b->id;

    expect(fn () => $item->save())->toThrow(TenantMismatch::class);
});
