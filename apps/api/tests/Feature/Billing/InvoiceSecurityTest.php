<?php

use App\Billing\SubscriptionBilling;
use App\Tenancy\TenantDatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
    $this->a = $this->subscription([], 'pm_fake_ok_x', 'negocioa');
    $this->b = $this->subscription([], 'pm_fake_ok_x', 'negociob');
    app(SubscriptionBilling::class)->run(now());
});

it('CA-10 shows the api role only the invoices of the tenant in context', function () {
    expect(DB::table('invoices')->count())->toBe(0);

    app(TenantDatabaseContext::class)->apply($this->a->tenant_id);

    expect(DB::table('invoices')->count())->toBe(1);
    expect(DB::table('invoices')->value('tenant_id'))->toBe($this->a->tenant_id);
});

it('CA-16 does not let the api role write invoices', function () {
    app(TenantDatabaseContext::class)->apply($this->a->tenant_id);

    try {
        DB::table('invoices')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->a->tenant_id, 'subscription_id' => $this->a->id,
            'period_start' => now(), 'period_end' => now()->addMonth(), 'amount_cents' => 1, 'currency' => 'BRL',
            'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $code = null;
    } catch (QueryException $e) {
        $code = $e->getCode();
    }

    expect($code)->toBe('42501');
});

it('has row level security forced on invoices', function () {
    $row = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'invoices'");

    expect($row->relrowsecurity)->toBeTrue()->and($row->relforcerowsecurity)->toBeTrue();
});

it('lets the platform role write an invoice only for the tenant it informs', function () {
    $platform = DB::connection('pgsql_platform');

    $attempt = fn () => $platform->transaction(function ($db) {
        $db->statement("select set_config('app.tenant_id', ?, true)", [$this->a->tenant_id]);
        $db->table('invoices')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->b->tenant_id, 'subscription_id' => $this->b->id,
            'period_start' => now()->addYear(), 'period_end' => now()->addYears(2), 'amount_cents' => 1, 'currency' => 'BRL',
            'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    expect($attempt)->toThrow(QueryException::class);
});
