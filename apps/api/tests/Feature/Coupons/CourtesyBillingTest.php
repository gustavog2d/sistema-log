<?php

use App\Billing\PaymentGateway;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedCatalog();
});

it('CA-09 activates a courtesy account at the end of the trial with a zero invoice and no gateway call', function () {
    makeCoupon(['code' => 'CORTESIA', 'percent_off' => 100]);
    $this->signup(['coupon_code' => 'CORTESIA', 'payment_method_token' => null])->assertCreated();
    DB::connection('pgsql_admin')->table('subscriptions')->update(['trial_ends_at' => now()->subMinute()]);
    $customers = count(app(PaymentGateway::class)->activeCustomers());

    $this->artisan('billing:run')->assertSuccessful();

    $subscription = DB::connection('pgsql_admin')->table('subscriptions')->first();
    $invoice = DB::connection('pgsql_admin')->table('invoices')->first();

    expect($subscription->status)->toBe('active')
        ->and($invoice->amount_cents)->toBe(0)->and($invoice->status)->toBe('paid')->and($invoice->gateway_charge_ref)->toBeNull()
        ->and(count(app(PaymentGateway::class)->activeCustomers()))->toBe($customers);
});

it('CA-09 keeps issuing zero invoices on the following renewals', function () {
    makeCoupon(['code' => 'CORTESIA', 'percent_off' => 100, 'max_redemptions' => 2]);
    $this->signup(['coupon_code' => 'CORTESIA', 'payment_method_token' => null])->assertCreated();
    DB::connection('pgsql_admin')->table('subscriptions')->update(['trial_ends_at' => now()->subMinute()]);
    $this->artisan('billing:run')->assertSuccessful();
    DB::connection('pgsql_admin')->table('subscriptions')->update(['current_period_end' => now()->subDay()]);

    $this->artisan('billing:run')->assertSuccessful();

    expect(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(2)
        ->and(DB::connection('pgsql_admin')->table('invoices')->sum('amount_cents'))->toBe(0)
        ->and(DB::connection('pgsql_admin')->table('subscriptions')->value('status'))->toBe('active');
});
