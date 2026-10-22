<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

function billingAsOf($test, string $asOf): void
{
    $test->artisan('billing:run', ['--as-of' => $asOf])->assertSuccessful();
}

it('CA-08 registers the cancellation during the trial, keeps access and never charges', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'trialing', ['trial_ends_at' => $this->at('2026-12-01 09:00:00')]);

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')
        ->assertOk()
        ->assertJsonPath('status', 'trialing')
        ->assertJsonPath('cancel_at_period_end', true)
        ->assertJsonPath('can_write', true);

    manage($this, $token, 'GET', 'neg', 'subscription')->assertOk()->assertJsonPath('can_write', true);
    billingAsOf($this, '2026-12-01T10:00:00Z');

    expect(subscriptionOf($ws)->status)->toBe('canceled')
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0);
});

it('CA-09 does not renew an active subscription with a pending cancellation', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'active', ['current_period_end' => $this->at('2026-12-01 09:00:00')]);

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk()->assertJsonPath('status', 'active');
    billingAsOf($this, '2026-11-30T09:00:00Z');
    expect(subscriptionOf($ws)->status)->toBe('active');

    billingAsOf($this, '2026-12-01T09:00:01Z');

    expect(subscriptionOf($ws)->status)->toBe('canceled')
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0)
        ->and(DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'subscription.canceled')->count())->toBe(1);
});

it('CA-10 cancels a suspended subscription right away', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'suspended', ['suspended_at' => now()->subDay()]);

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk()->assertJsonPath('status', 'canceled')->assertJsonPath('can_write', false);

    expect(subscriptionOf($ws)->canceled_at)->not->toBeNull();
});

it('CA-11 answers 200 without changes to a repeated request and 409 when already canceled', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'active');

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();
    $first = subscriptionOf($ws)->canceled_at;
    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();

    expect(subscriptionOf($ws)->canceled_at)->toBe($first);

    DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $ws->tenant_id)->update(['status' => 'canceled']);
    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertStatus(409);
});

it('CA-12 undoes a pending cancellation and the charge goes on', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'active', ['current_period_end' => $this->at('2026-12-01 09:00:00')]);

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();
    manage($this, $token, 'POST', 'neg', 'subscription/reactivate')->assertOk()->assertJsonPath('cancel_at_period_end', false);

    expect(subscriptionOf($ws)->canceled_at)->toBeNull();

    billingAsOf($this, '2026-12-01T09:00:01Z');
    expect(subscriptionOf($ws)->status)->toBe('active')
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(1);
});

it('CA-13 refuses to reactivate when nothing is pending or when it is canceled', function (string $status) {
    [, $token] = ownerOf($this, 'neg', $status);

    manage($this, $token, 'POST', 'neg', 'subscription/reactivate')->assertStatus(409);
})->with(['active', 'trialing', 'canceled']);
