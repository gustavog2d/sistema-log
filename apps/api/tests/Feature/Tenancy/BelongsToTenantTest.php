<?php

use App\Tenancy\Exceptions\TenantContextMissing;
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

it('scopes queries to the tenant in context', function () {
    enterTenant($this->a);

    expect(ProbeItem::pluck('name')->all())->toBe(['a-1']);
});

it('fills tenant_id when creating', function () {
    enterTenant($this->a);

    $item = ProbeItem::create(['name' => 'novo']);

    expect($item->tenant_id)->toBe($this->a->id);
});

it('fails closed when there is no tenant in context', function () {
    expect(fn () => ProbeItem::all())->toThrow(TenantContextMissing::class);
});
