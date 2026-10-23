<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('CA-01 lists the tenants newest first with a cursor', function () {
    foreach (['estudio1', 'estudio2', 'estudio3'] as $i => $slug) {
        adminTenant($this, $slug, 'active', [], "dona-{$slug}@exemplo.com");
        DB::connection('pgsql_admin')->table('tenants')->where('slug', $slug)->update(['created_at' => now()->subDays(3 - $i)]);
    }

    $admin = $this->adminToken();
    $page = adm($this, $admin, 'GET', 'tenants?limit=2')->assertOk();

    expect(collect($page->json('data'))->pluck('slug')->all())->toBe(['estudio3', 'estudio2'])->and($page->json('next_cursor'))->not->toBeNull();

    $next = adm($this, $admin, 'GET', 'tenants?limit=2&cursor='.$page->json('next_cursor'))->assertOk();
    expect(collect($next->json('data'))->pluck('slug')->all())->toBe(['estudio1'])->and($next->json('next_cursor'))->toBeNull();
});

it('CA-01 filters by name or address and by subscription status', function () {
    adminTenant($this, 'estudiotinta', 'active', [], 'a@exemplo.com');
    adminTenant($this, 'barbearia', 'suspended', [], 'b@exemplo.com');
    adminTenant($this, 'manicure', 'trialing', [], 'c@exemplo.com');
    $admin = $this->adminToken();

    expect(collect(adm($this, $admin, 'GET', 'tenants?q=tinta')->json('data'))->pluck('slug')->all())->toBe(['estudiotinta'])
        ->and(collect(adm($this, $admin, 'GET', 'tenants?status=suspended')->json('data'))->pluck('slug')->all())->toBe(['barbearia'])
        ->and(adm($this, $admin, 'GET', 'tenants')->json('data'))->toHaveCount(3);
});

it('CA-01 treats a search with wildcard characters as plain text', function () {
    adminTenant($this, 'estudio', 'active', [], 'a@exemplo.com');

    expect(adm($this, $this->adminToken(), 'GET', 'tenants?q='.urlencode('%'))->json('data'))->toBe([]);
});

it('CA-01 refuses a limit out of range and an unknown status', function (string $query) {
    adm($this, $this->adminToken(), 'GET', "tenants?{$query}")->assertUnprocessable();
})->with(['limit=0', 'limit=51', 'status=banana']);

it('CA-02 shows the detail without secrets', function () {
    $ws = adminTenant($this, 'neg', 'active');
    $db = DB::connection('pgsql_admin');
    $db->table('invoices')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $ws->id, 'subscription_id' => $ws->subscription_id, 'period_start' => now()->subMonth(), 'period_end' => now(),
        'amount_cents' => 5000, 'currency' => 'BRL', 'status' => 'paid', 'gateway_charge_ref' => 'ch_secreto', 'paid_at' => now()->subMonth(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $coupon = makeCoupon(['code' => 'PROMO20']);
    $db->table('coupon_redemptions')->insert(['id' => (string) Str::uuid(), 'coupon_id' => $coupon->id, 'tenant_id' => $ws->id, 'subscription_id' => $ws->subscription_id, 'percent_off' => 20, 'original_price_cents' => 5000, 'final_price_cents' => 4000]);

    $detail = adm($this, $this->adminToken(), 'GET', "tenants/{$ws->id}")->assertOk();

    expect($detail->json('owner'))->toBe(['name' => 'Dona neg', 'email' => 'dona@exemplo.com'])
        ->and($detail->json('active_people'))->toBe(1)
        ->and($detail->json('payment_method'))->toBe(['type' => 'card', 'brand' => 'visa', 'last4' => '4242'])
        ->and($detail->json('coupon'))->toBe(['code' => 'PROMO20', 'percent_off' => 20])
        ->and($detail->json('invoices'))->toHaveCount(1)
        ->and(collect($detail->json('entitlements'))->firstWhere('feature', 'usuarios.max')['source'])->toBe('default')
        ->and(json_encode($detail->json()))->not->toContain('pm_fake')->not->toContain('ch_secreto')->not->toContain('password')->not->toContain('cus_fake');
});

it('CA-12 lets support read but answers 404 for an unknown tenant and 401 without login', function () {
    $ws = adminTenant($this, 'neg');

    $this->getJson('http://app.test/api/v1/platform/tenants')->assertUnauthorized();
    $support = $this->supportToken();
    adm($this, $support, 'GET', 'tenants')->assertOk();
    adm($this, $support, 'GET', "tenants/{$ws->id}")->assertOk();
    adm($this, $support, 'GET', 'tenants/'.Str::uuid())->assertNotFound();
});

it('CA-12 is not reachable on a business host', function () {
    $this->getJson('http://neg.app.test/api/v1/platform/tenants')->assertNotFound();
});
