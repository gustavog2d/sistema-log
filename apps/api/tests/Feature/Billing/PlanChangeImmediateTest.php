<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-03 changes at once during the trial, up or down, without charging', function () {
    [$ws, $token] = planOwner($this, 'neg', 'trialing', ['trial_ends_at' => Carbon::parse('2026-11-15 12:00:00')]);

    changePlan($this, $token, 'neg', 'estudio')->assertOk()
        ->assertJsonPath('plan_change.kind', 'now')
        ->assertJsonPath('plan_change.charged_cents', 0)
        ->assertJsonPath('plan_change.price_cents', 25000)
        ->assertJsonPath('status', 'trialing');

    expect(planKeyOf($ws))->toBe('estudio')->and(subscriptionOf($ws)->price_cents)->toBe(25000);

    changePlan($this, $token, 'neg', 'essencial', 'k2')->assertOk()->assertJsonPath('plan_change.price_cents', 1500);

    $sub = subscriptionOf($ws);
    expect(planKeyOf($ws))->toBe('essencial')
        ->and($sub->pending_plan_id)->toBeNull()
        ->and(Carbon::parse($sub->trial_ends_at, 'UTC')->toDateTimeString())->toBe('2026-11-15 12:00:00')
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0)
        ->and(auditCount('subscription.plan_changed'))->toBe(2);
});

it('CA-04 charges the proportional difference on an upgrade and keeps the period', function () {
    [$ws, $token] = planOwner($this);

    // Faltam 20 dos 30 dias: (25000 - 5000) x 20/30 = 13333,33.
    changePlan($this, $token, 'neg', 'estudio')->assertOk()
        ->assertJsonPath('plan_change.kind', 'now')
        ->assertJsonPath('plan_change.charged_cents', 13333)
        ->assertJsonPath('plan_change.price_cents', 25000);

    $sub = subscriptionOf($ws);
    $invoices = DB::connection('pgsql_admin')->table('invoices')->where('subscription_id', $sub->id)->get();

    expect(planKeyOf($ws))->toBe('estudio')
        ->and($sub->price_cents)->toBe(25000)
        ->and($sub->status)->toBe('active')
        ->and(Carbon::parse($sub->current_period_end, 'UTC')->toDateTimeString())->toBe('2026-11-30 12:00:00')
        ->and($invoices)->toHaveCount(1)
        ->and($invoices[0]->amount_cents)->toBe(13333)
        ->and($invoices[0]->status)->toBe('paid')
        ->and(Carbon::parse($invoices[0]->period_end, 'UTC')->toDateTimeString())->toBe('2026-11-30 12:00:00');
});

it('CA-04 changes at once without charging when the price is the same', function () {
    [$ws, $token] = planOwner($this);
    DB::connection('pgsql_admin')->table('plans')->where('key', 'essencial')->update(['monthly_price_cents' => 5000]);

    changePlan($this, $token, 'neg', 'essencial')->assertOk()
        ->assertJsonPath('plan_change.kind', 'now')
        ->assertJsonPath('plan_change.charged_cents', 0);

    expect(planKeyOf($ws))->toBe('essencial')->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0);
});

it('CA-05 changes nothing and does not suspend when the upgrade charge is refused', function () {
    [$ws, $token] = planOwner($this);
    DB::connection('pgsql_admin')->table('payment_methods')->where('id', subscriptionOf($ws)->payment_method_id)->update(['gateway_ref' => 'pm_fake_fail_x']);

    changePlan($this, $token, 'neg', 'estudio')->assertStatus(402)->assertJsonPath('code', 'payment_failed');

    $sub = subscriptionOf($ws);
    expect(planKeyOf($ws))->toBe('profissional')
        ->and($sub->price_cents)->toBe(5000)
        ->and($sub->status)->toBe('active')
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0)
        ->and(auditCount('subscription.plan_changed'))->toBe(0);
});

it('CA-13 keeps the coupon percentage on the new price and the courtesy free', function () {
    [$ws, $token] = planOwner($this, 'neg', 'trialing', ['trial_ends_at' => Carbon::parse('2026-11-15 12:00:00'), 'price_cents' => 4000]);
    $coupon = (string) Str::uuid();
    $db = DB::connection('pgsql_admin');
    $db->table('coupons')->insert(['id' => $coupon, 'code' => 'AMIGA20', 'percent_off' => 20, 'status' => 'active', 'redeemed_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $db->table('coupon_redemptions')->insert(['id' => (string) Str::uuid(), 'coupon_id' => $coupon, 'tenant_id' => $ws->tenant_id, 'subscription_id' => subscriptionOf($ws)->id, 'percent_off' => 20, 'original_price_cents' => 5000, 'final_price_cents' => 4000]);

    changePlan($this, $token, 'neg', 'estudio')->assertOk()->assertJsonPath('plan_change.price_cents', 20000);

    $db->table('coupon_redemptions')->where('tenant_id', $ws->tenant_id)->update(['percent_off' => 100]);
    changePlan($this, $token, 'neg', 'essencial', 'k2')->assertOk()->assertJsonPath('plan_change.price_cents', 0);
});

it('CA-16 charges only once for the same idempotency key', function () {
    [$ws, $token] = planOwner($this);

    $first = changePlan($this, $token, 'neg', 'estudio', 'mesma')->assertOk();
    $second = changePlan($this, $token, 'neg', 'estudio', 'mesma')->assertOk();

    expect($second->json())->toBe($first->json())
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(1);
});

it('CA-17 audits the change without any card data', function () {
    [, $token] = planOwner($this);

    changePlan($this, $token, 'neg', 'estudio')->assertOk();

    $log = DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'subscription.plan_changed')->first();
    $meta = json_decode($log->metadata, true);

    expect($meta)->toMatchArray(['from' => 'profissional', 'to' => 'estudio', 'price_cents' => 25000, 'charged_cents' => 13333])
        ->and(json_encode($meta))->not->toContain('pm_fake')->not->toContain('4242');
});
