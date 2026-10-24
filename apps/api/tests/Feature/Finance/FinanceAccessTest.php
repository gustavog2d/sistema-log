<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

const FINANCE_READS = ['finance/summary?from=2026-11-01&to=2026-11-30', 'finance/monthly?to=2026-11', 'finance/calendar?month=2026-11', 'finance/goal'];

it('CA-10 lets only owner and manager read and set the goal', function () {
    $w = apptTeam($this);

    foreach (['reception', 'ana', 'bia'] as $who) {
        foreach (FINANCE_READS as $path) {
            expApi($this, $w->tokens[$who], 'GET', $path)->assertForbidden();
        }
        expApi($this, $w->tokens[$who], 'PUT', 'finance/goal', ['monthly_goal_cents' => 1000])->assertForbidden();
    }
    foreach (['owner', 'manager'] as $who) {
        foreach (FINANCE_READS as $path) {
            expApi($this, $w->tokens[$who], 'GET', $path)->assertOk();
        }
    }
    expApi($this, $w->tokens['manager'], 'PUT', 'finance/goal', ['monthly_goal_cents' => 1000])->assertOk();
});

it('CA-10 gives the support access read-only', function () {
    $w = apptTeam($this);
    $token = supportAccessToken($w->ws);

    foreach (FINANCE_READS as $path) {
        expApi($this, $token, 'GET', $path)->assertOk();
    }
    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => 1000])->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
});

it('CA-11 keeps reading but blocks the goal when the subscription is suspended or canceled', function (string $status, string $code) {
    Carbon::setTestNow('2026-11-02 12:00:00');
    [, $token] = ownerOf($this, 'neg', $status, $status === 'suspended' ? ['suspended_at' => now()->subDay()] : []);

    foreach (FINANCE_READS as $path) {
        expApi($this, $token, 'GET', $path)->assertOk();
    }
    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => 1000])->assertForbidden()->assertJsonPath('code', $code);
})->with([['suspended', 'subscription_suspended'], ['canceled', 'subscription_canceled']]);

it('CA-12 shows each business only its own numbers and asks for a login', function () {
    $a = finWorld($this, 'negocioa');
    $b = finWorld($this, 'negociob');
    finAppointment($b, '2026-11-03 13:00:00', 10000, 'completed', 40, [[10000, '2026-11-03 13:00:00', 'pix']]);
    seedExpense($b->ws, seedExpenseCategory($b->ws), ['spent_on' => '2026-11-05', 'amount_cents' => 777]);

    // Sem login primeiro: o token dos pedidos seguintes fica guardado no cliente de teste.
    $this->getJson('http://negocioa.app.test/api/v1/finance/goal')->assertUnauthorized();
    $summary = expApi($this, $a->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30', [], 'negocioa')->assertOk();
    expect($summary->json('received_cents'))->toBe(0)->and($summary->json('expected_cents'))->toBe(0)->and($summary->json('expenses_cents'))->toBe(0)
        ->and(collect(expApi($this, $a->token, 'GET', 'finance/monthly?to=2026-11', [], 'negocioa')->json('months'))->sum('received_cents'))->toBe(0)
        ->and(collect(expApi($this, $a->token, 'GET', 'finance/calendar?month=2026-11', [], 'negocioa')->json('days'))->sum('expenses_cents'))->toBe(0);
    expApi($this, $a->token, 'GET', 'finance/goal', [], 'negociob')->assertUnauthorized();
});
