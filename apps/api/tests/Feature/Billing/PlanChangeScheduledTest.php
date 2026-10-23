<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-06 schedules a downgrade for the end of the period without charging', function () {
    [$ws, $token] = planOwner($this);

    changePlan($this, $token, 'neg', 'essencial')->assertOk()
        ->assertJsonPath('plan_change.kind', 'next_cycle')
        ->assertJsonPath('plan_change.charged_cents', 0)
        ->assertJsonPath('plan_change.price_cents', 1500)
        ->assertJsonPath('pending_plan_change.plan.key', 'essencial')
        ->assertJsonPath('pending_plan_change.price_cents', 1500)
        ->assertJsonPath('pending_plan_change.effective_at', Carbon::parse('2026-11-30 12:00:00')->toIso8601String());

    $sub = subscriptionOf($ws);
    expect(planKeyOf($ws))->toBe('profissional')
        ->and($sub->price_cents)->toBe(5000)
        ->and($sub->pending_price_cents)->toBe(1500)
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0)
        ->and(auditCount('subscription.plan_change_scheduled'))->toBe(1);
});

it('CA-06 replaces the pending change with a new one', function () {
    [$ws, $token] = planOwner($this);
    DB::connection('pgsql_admin')->table('plans')->where('key', 'estudio')->update(['monthly_price_cents' => 4000]);

    changePlan($this, $token, 'neg', 'essencial')->assertOk();
    changePlan($this, $token, 'neg', 'estudio', 'k2')->assertOk()->assertJsonPath('plan_change.kind', 'next_cycle');

    $pending = DB::connection('pgsql_admin')->table('plans')->where('id', subscriptionOf($ws)->pending_plan_id)->value('key');
    expect($pending)->toBe('estudio');
});

it('CA-07 blocks a downgrade when the team is bigger than the new plan allows', function () {
    [$ws, $token] = planOwner($this);
    planUserLimits($this, ['essencial' => 2, 'profissional' => 5, 'estudio' => null]);
    addPeople($ws, 2);

    changePlan($this, $token, 'neg', 'essencial')->assertStatus(409)
        ->assertJsonPath('code', 'plan_limit_exceeded')
        ->assertJsonPath('violations.0.feature', 'usuarios.max')
        ->assertJsonPath('violations.0.limit', 2)
        ->assertJsonPath('violations.0.usage', 3);

    expect(subscriptionOf($ws)->pending_plan_id)->toBeNull();
});

it('CA-07 counts pending invitations and also blocks during the trial', function () {
    [$ws, $token] = planOwner($this, 'neg', 'trialing', ['trial_ends_at' => Carbon::parse('2026-11-15 12:00:00')]);
    planUserLimits($this, ['essencial' => 2, 'profissional' => 5, 'estudio' => null]);
    addPeople($ws, 1);
    DB::connection('pgsql_admin')->table('invitations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $ws->tenant_id, 'email' => 'conv@exemplo.com', 'name' => 'Conv', 'role' => 'reception',
        'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDays(3), 'created_at' => now(), 'updated_at' => now(),
    ]);

    changePlan($this, $token, 'neg', 'essencial')->assertStatus(409)->assertJsonPath('violations.0.usage', 3);
    expect(planKeyOf($ws))->toBe('profissional');
});

it('CA-07 applies the owner exceptions when checking the limit', function () {
    [$ws, $token] = planOwner($this);
    planUserLimits($this, ['essencial' => 2, 'profissional' => 5, 'estudio' => null]);
    addPeople($ws, 3);
    $db = DB::connection('pgsql_admin');
    $db->table('tenant_entitlement_overrides')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $ws->tenant_id, 'feature_id' => $db->table('features')->where('key', 'usuarios.max')->value('id'),
        'enabled' => true, 'limit' => 10, 'visible' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    changePlan($this, $token, 'neg', 'essencial')->assertOk()->assertJsonPath('plan_change.kind', 'next_cycle');
});

it('CA-11 cancels the pending change and refuses when there is none', function () {
    [$ws, $token] = planOwner($this);

    manage($this, $token, 'DELETE', 'neg', 'subscription/plan-change')->assertStatus(409)->assertJsonPath('code', 'no_pending_plan_change');

    changePlan($this, $token, 'neg', 'essencial')->assertOk();
    manage($this, $token, 'DELETE', 'neg', 'subscription/plan-change')->assertOk()->assertJsonPath('pending_plan_change', null);

    expect(subscriptionOf($ws)->pending_plan_id)->toBeNull()->and(auditCount('subscription.plan_change_canceled'))->toBe(1);
});

it('CA-14 previews without writing anything', function () {
    [$ws, $token] = planOwner($this);
    planUserLimits($this, ['essencial' => 2, 'profissional' => 5, 'estudio' => null]);
    addPeople($ws, 2);
    $before = (array) subscriptionOf($ws);

    manage($this, $token, 'GET', 'neg', 'subscription/plan-change/preview?plan=estudio')->assertOk()
        ->assertJsonPath('kind', 'now')->assertJsonPath('charge_now_cents', 13333)->assertJsonPath('price_cents', 25000)->assertJsonPath('violations', []);
    manage($this, $token, 'GET', 'neg', 'subscription/plan-change/preview?plan=essencial')->assertOk()
        ->assertJsonPath('kind', 'next_cycle')->assertJsonPath('charge_now_cents', 0)
        ->assertJsonPath('effective_at', Carbon::parse('2026-11-30 12:00:00')->toIso8601String())
        ->assertJsonPath('violations.0.usage', 3);

    expect((array) subscriptionOf($ws))->toBe($before)->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0);
});

it('CA-15 shows the pending change on the subscription status', function () {
    [, $token] = planOwner($this);

    manage($this, $token, 'GET', 'neg', 'subscription')->assertOk()->assertJsonPath('pending_plan_change', null);

    changePlan($this, $token, 'neg', 'essencial')->assertOk();
    manage($this, $token, 'GET', 'neg', 'subscription')->assertOk()->assertJsonPath('pending_plan_change.plan.name', 'Essencial');
});
