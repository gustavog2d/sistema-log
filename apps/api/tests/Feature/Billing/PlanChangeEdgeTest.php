<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

function redeemCoupon(object $workspace, int $percent, int $original, int $final): void
{
    $db = DB::connection('pgsql_admin');
    $coupon = (string) Str::uuid();
    $db->table('coupons')->insert(['id' => $coupon, 'code' => 'CUPOM'.$percent, 'percent_off' => $percent, 'status' => 'active', 'redeemed_count' => 1, 'created_at' => now(), 'updated_at' => now()]);
    $db->table('coupon_redemptions')->insert(['id' => (string) Str::uuid(), 'coupon_id' => $coupon, 'tenant_id' => $workspace->tenant_id, 'subscription_id' => subscriptionOf($workspace)->id, 'percent_off' => $percent, 'original_price_cents' => $original, 'final_price_cents' => $final]);
}

it('CA-13 prorates the difference between the discounted prices on an active upgrade', function () {
    [$ws, $token] = planOwner($this, 'neg', 'active', ['price_cents' => 4000]);
    redeemCoupon($ws, 20, 5000, 4000);

    // Estúdio com 20% = 20000; diferença 16000 x 20/30 = 10666,67.
    changePlan($this, $token, 'neg', 'estudio')->assertOk()->assertJsonPath('plan_change.charged_cents', 10667)->assertJsonPath('plan_change.price_cents', 20000);
});

it('CA-13 moves a courtesy subscription to a paid plan for free, without invoice', function () {
    [$ws, $token] = planOwner($this, 'neg', 'active', ['price_cents' => 0]);
    redeemCoupon($ws, 100, 5000, 0);

    changePlan($this, $token, 'neg', 'estudio')->assertOk()->assertJsonPath('plan_change.charged_cents', 0)->assertJsonPath('plan_change.price_cents', 0);

    expect(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0)->and(planKeyOf($ws))->toBe('estudio');
});

it('CA-04 prorates a yearly subscription over the days of the year', function () {
    [$ws, $token] = planOwner($this, 'neg', 'active', [
        'cycle' => 'yearly', 'price_cents' => 54000,
        'current_period_start' => Carbon::parse('2026-01-10 12:00:00'), 'current_period_end' => Carbon::parse('2027-01-10 12:00:00'),
    ]);

    // Estúdio anual = 270000; diferença 216000; faltam 61 dos 365 dias.
    changePlan($this, $token, 'neg', 'estudio')->assertOk()
        ->assertJsonPath('plan_change.charged_cents', (int) round(216000 * 61 / 365))
        ->assertJsonPath('plan_change.price_cents', 270000);
});

it('CA-04 rounds half a cent up', function () {
    [, $token] = planOwner($this, 'neg', 'active', [
        'current_period_start' => Carbon::parse('2026-10-26 12:00:00'), 'current_period_end' => Carbon::parse('2026-11-25 12:00:00'),
    ]);
    DB::connection('pgsql_admin')->table('plans')->where('key', 'estudio')->update(['monthly_price_cents' => 10001]);

    // Metade do período: 5001 / 2 = 2500,5 => 2501.
    changePlan($this, $token, 'neg', 'estudio')->assertOk()->assertJsonPath('plan_change.charged_cents', 2501);
});

it('CA-04 changes the plan without charging when the period already ended', function () {
    [$ws, $token] = planOwner($this, 'neg', 'active', [
        'current_period_start' => Carbon::parse('2026-10-01 12:00:00'), 'current_period_end' => Carbon::parse('2026-11-01 12:00:00'),
    ]);

    changePlan($this, $token, 'neg', 'estudio')->assertOk()->assertJsonPath('plan_change.charged_cents', 0);

    expect(planKeyOf($ws))->toBe('estudio')->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0);
});

it('CA-05 charges again with the new card after a refused upgrade', function () {
    [$ws, $token] = planOwner($this);
    $db = DB::connection('pgsql_admin');
    $db->table('payment_methods')->where('id', subscriptionOf($ws)->payment_method_id)->update(['gateway_ref' => 'pm_fake_fail_x']);

    changePlan($this, $token, 'neg', 'estudio', 'k1')->assertStatus(402);
    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'k-card')->assertOk();
    changePlan($this, $token, 'neg', 'estudio', 'k2')->assertOk();

    expect(planKeyOf($ws))->toBe('estudio')->and($db->table('invoices')->count())->toBe(1);
});

it('CA-04 charges again when the same plan is reached a second time in the period', function () {
    [$ws, $token] = planOwner($this);
    $db = DB::connection('pgsql_admin');

    changePlan($this, $token, 'neg', 'estudio', 'k1')->assertOk();
    // Volta ao plano de entrada na hora (preço igual) e sobe de novo: é outra cobrança, não a resposta guardada da primeira.
    $db->table('plans')->where('key', 'profissional')->update(['monthly_price_cents' => 25000]);
    changePlan($this, $token, 'neg', 'profissional', 'k2')->assertOk()->assertJsonPath('plan_change.charged_cents', 0);
    $db->table('plans')->where('key', 'profissional')->update(['monthly_price_cents' => 5000]);
    $db->table('subscriptions')->where('id', subscriptionOf($ws)->id)->update(['price_cents' => 5000]);
    // Outro instante: a fatura de cada cobrança é única por assinatura e início.
    Carbon::setTestNow('2026-11-10 12:05:00');
    changePlan($this, $token, 'neg', 'estudio', 'k3')->assertOk();

    expect($db->table('invoices')->count())->toBe(2);
});

it('CA-11 lets the owner undo a pending change even with a cancellation pending, but not when suspended or canceled', function () {
    [$ws, $token] = planOwner($this);
    changePlan($this, $token, 'neg', 'essencial')->assertOk();

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();
    manage($this, $token, 'DELETE', 'neg', 'subscription/plan-change')->assertOk()->assertJsonPath('pending_plan_change', null);

    changePlan($this, $token, 'neg', 'essencial', 'k9')->assertStatus(409)->assertJsonPath('code', 'cancel_pending');

    foreach (['suspended' => 'subscription_suspended', 'canceled' => 'subscription_canceled'] as $status => $code) {
        DB::connection('pgsql_admin')->table('subscriptions')->where('id', subscriptionOf($ws)->id)->update(['status' => $status]);
        manage($this, $token, 'DELETE', 'neg', 'subscription/plan-change')->assertStatus(403)->assertJsonPath('code', $code);
    }
});

it('CA-17 audits scheduling, canceling, applying and skipping without card data', function () {
    [$ws, $token] = planOwner($this);
    $db = DB::connection('pgsql_admin');

    changePlan($this, $token, 'neg', 'essencial', 'k1')->assertOk();
    manage($this, $token, 'DELETE', 'neg', 'subscription/plan-change')->assertOk();
    changePlan($this, $token, 'neg', 'essencial', 'k2')->assertOk();
    renewAt($this, '2026-11-30T12:00:01Z');
    $db->table('plans')->where('key', 'estudio')->update(['status' => 'archived']);

    foreach (['subscription.plan_change_scheduled' => 2, 'subscription.plan_change_canceled' => 1, 'subscription.plan_change_applied' => 1] as $event => $count) {
        $rows = $db->table('platform_audit_logs')->where('event', $event)->get();
        expect($rows)->toHaveCount($count);
        foreach ($rows as $row) {
            expect($row->metadata)->not->toContain('pm_fake')->not->toContain('4242')->toContain($ws->tenant_id);
        }
    }
});

it('CA-09 drops a scheduled change when the plan was archived before the renewal', function () {
    [$ws, $token] = planOwner($this);
    changePlan($this, $token, 'neg', 'essencial')->assertOk();
    DB::connection('pgsql_admin')->table('plans')->where('key', 'essencial')->update(['status' => 'archived']);

    renewAt($this, '2026-11-30T12:00:01Z');

    $log = DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'subscription.plan_change_skipped')->first();
    expect(planKeyOf($ws))->toBe('profissional')
        ->and(subscriptionOf($ws)->pending_plan_id)->toBeNull()
        ->and(json_decode($log->metadata, true)['reason'])->toBe('plan_unavailable')
        ->and(DB::connection('pgsql_admin')->table('invoices')->first()->amount_cents)->toBe(5000);
});

it('CA-08 keeps the price frozen at the moment of the scheduling', function () {
    [$ws, $token] = planOwner($this);
    changePlan($this, $token, 'neg', 'essencial')->assertOk();
    DB::connection('pgsql_admin')->table('plans')->where('key', 'essencial')->update(['monthly_price_cents' => 2000]);

    renewAt($this, '2026-11-30T12:00:01Z');

    expect(DB::connection('pgsql_admin')->table('invoices')->first()->amount_cents)->toBe(1500)->and(subscriptionOf($ws)->price_cents)->toBe(1500);
});

it('CA-17 records that an immediate upgrade replaced a pending change', function () {
    [, $token] = planOwner($this);
    DB::connection('pgsql_admin')->table('plans')->where('key', 'essencial')->update(['monthly_price_cents' => 1500]);
    changePlan($this, $token, 'neg', 'essencial', 'k1')->assertOk();
    changePlan($this, $token, 'neg', 'estudio', 'k2')->assertOk();

    $meta = json_decode(DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'subscription.plan_changed')->first()->metadata, true);
    expect($meta['cleared_pending'])->toBeTrue();
});

it('CA-18 lets each business change only its own subscription', function () {
    [$a, $tokenA] = planOwner($this, 'negocioa');
    [$b, $tokenB] = planOwner($this, 'negociob');

    changePlan($this, $tokenA, 'negocioa', 'essencial')->assertOk();
    changePlan($this, $tokenB, 'negociob', 'estudio')->assertOk();

    expect(subscriptionOf($a)->pending_plan_id)->not->toBeNull()
        ->and(planKeyOf($a))->toBe('profissional')
        ->and(subscriptionOf($b)->pending_plan_id)->toBeNull()
        ->and(planKeyOf($b))->toBe('estudio');
});
