<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

function renewAt($test, string $asOf): void
{
    Carbon::setTestNow(Carbon::parse($asOf, 'UTC'));
    $test->artisan('billing:run', ['--as-of' => $asOf])->assertSuccessful();
}

it('CA-08 applies the scheduled downgrade at the renewal and charges the new price', function () {
    [$ws, $token] = planOwner($this);
    changePlan($this, $token, 'neg', 'essencial')->assertOk();

    renewAt($this, '2026-11-30T12:00:01Z');

    $sub = subscriptionOf($ws);
    $invoice = DB::connection('pgsql_admin')->table('invoices')->where('subscription_id', $sub->id)->first();

    expect(planKeyOf($ws))->toBe('essencial')
        ->and($sub->price_cents)->toBe(1500)
        ->and($sub->pending_plan_id)->toBeNull()
        ->and($sub->pending_price_cents)->toBeNull()
        ->and($sub->status)->toBe('active')
        ->and($invoice->amount_cents)->toBe(1500)
        ->and(auditCount('subscription.plan_change_applied'))->toBe(1);
});

it('CA-09 keeps the current plan when the usage passed the limit by the renewal', function () {
    [$ws, $token] = planOwner($this);
    planUserLimits($this, ['essencial' => 2, 'profissional' => 5, 'estudio' => null]);
    changePlan($this, $token, 'neg', 'essencial')->assertOk();
    addPeople($ws, 3);

    renewAt($this, '2026-11-30T12:00:01Z');

    $sub = subscriptionOf($ws);
    $invoice = DB::connection('pgsql_admin')->table('invoices')->where('subscription_id', $sub->id)->first();

    expect(planKeyOf($ws))->toBe('profissional')
        ->and($sub->price_cents)->toBe(5000)
        ->and($sub->pending_plan_id)->toBeNull()
        ->and($invoice->amount_cents)->toBe(5000)
        ->and(auditCount('subscription.plan_change_skipped'))->toBe(1)
        ->and(auditCount('subscription.plan_change_applied'))->toBe(0);
});

it('CA-10 cancels without applying the change when a cancellation is pending', function () {
    [$ws, $token] = planOwner($this);
    changePlan($this, $token, 'neg', 'essencial')->assertOk();
    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();

    renewAt($this, '2026-11-30T12:00:01Z');

    expect(subscriptionOf($ws)->status)->toBe('canceled')
        ->and(planKeyOf($ws))->toBe('profissional')
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0)
        ->and(auditCount('subscription.plan_change_applied'))->toBe(0);
});

it('CA-08 suspends on a refused charge of the new price but still records the change', function () {
    [$ws, $token] = planOwner($this);
    changePlan($this, $token, 'neg', 'essencial')->assertOk();
    DB::connection('pgsql_admin')->table('payment_methods')->where('id', subscriptionOf($ws)->payment_method_id)->update(['gateway_ref' => 'pm_fake_fail_x']);

    renewAt($this, '2026-11-30T12:00:01Z');

    expect(subscriptionOf($ws)->status)->toBe('suspended')->and(planKeyOf($ws))->toBe('essencial');
});
