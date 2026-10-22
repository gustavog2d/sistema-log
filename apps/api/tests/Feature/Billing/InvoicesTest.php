<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

function addInvoices(object $workspace, int $count): void
{
    $subscription = subscriptionOf($workspace);

    foreach (range(1, $count) as $i) {
        DB::connection('pgsql_admin')->table('invoices')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'subscription_id' => $subscription->id,
            'period_start' => now()->subMonths($i + 1), 'period_end' => now()->subMonths($i), 'amount_cents' => 5000, 'currency' => 'BRL',
            'status' => 'paid', 'paid_at' => now()->subMonths($i + 1), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

it('CA-14 lists only the invoices of the business, newest first, with a cursor', function () {
    [$a, $token] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    addInvoices($a, 5);
    addInvoices($b, 3);

    $page = manage($this, $token, 'GET', 'negocioa', 'subscription/invoices?limit=2')->assertOk();
    expect($page->json('data'))->toHaveCount(2)->and($page->json('next_cursor'))->not->toBeNull();

    $starts = collect($page->json('data'))->pluck('period_start')->all();
    expect($starts[0] > $starts[1])->toBeTrue();

    $next = manage($this, $token, 'GET', 'negocioa', 'subscription/invoices?limit=10&cursor='.$page->json('next_cursor'))->assertOk();
    expect($next->json('data'))->toHaveCount(3)->and($next->json('next_cursor'))->toBeNull();

    $all = manage($this, $token, 'GET', 'negocioa', 'subscription/invoices?limit=50')->json('data');
    expect($all)->toHaveCount(5);
});

it('CA-14 refuses a limit out of range', function (string $limit) {
    [, $token] = ownerOf($this, 'neg');

    manage($this, $token, 'GET', 'neg', "subscription/invoices?limit={$limit}")->assertUnprocessable();
})->with(['0', '51', 'abc']);

it('CA-15 shows the payment method (without the gateway reference) and the pending cancellation on the subscription', function () {
    [, $token] = ownerOf($this, 'neg', 'active');

    $before = manage($this, $token, 'GET', 'neg', 'subscription')->assertOk();
    expect($before->json('payment_method'))->toBe(['type' => 'card', 'brand' => 'visa', 'last4' => '4242'])
        ->and($before->json('cancel_at_period_end'))->toBeFalse();

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();

    expect(manage($this, $token, 'GET', 'neg', 'subscription')->json('cancel_at_period_end'))->toBeTrue()
        ->and(json_encode($before->json()))->not->toContain('pm_fake');
});

it('CA-14 answers dates in ISO 8601', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    addInvoices($ws, 1);

    $invoice = manage($this, $token, 'GET', 'neg', 'subscription/invoices')->json('data.0');

    foreach (['period_start', 'period_end', 'paid_at'] as $field) {
        expect(Carbon::parse($invoice[$field])->utc()->toIso8601String())->toBe($invoice[$field]);
    }
});

it('CA-15 shows no next charge when a cancellation is pending', function () {
    [, $token] = ownerOf($this, 'neg', 'active');

    expect(manage($this, $token, 'GET', 'neg', 'subscription')->json('next_charge_at'))->not->toBeNull();

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();

    expect(manage($this, $token, 'GET', 'neg', 'subscription')->json('next_charge_at'))->toBeNull();
});

it('CA-16 does not show the payment method of another business', function () {
    [, $tokenA] = ownerOf($this, 'negocioa');
    ownerOf($this, 'negociob');
    DB::connection('pgsql_admin')->table('payment_methods')->update(['last4' => '9999']);
    DB::connection('pgsql_admin')->table('payment_methods')->where('tenant_id', DB::connection('pgsql_admin')->table('tenants')->where('slug', 'negocioa')->value('id'))->update(['last4' => '1111']);

    expect(manage($this, $tokenA, 'GET', 'negocioa', 'subscription')->json('payment_method.last4'))->toBe('1111');
});
