<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-14 returns bodies that respect the openapi contract', function () {
    $w = finWorld($this);
    finAppointment($w, '2026-11-03 13:00:00', 10000, 'completed', 40, [[4000, '2026-11-03 13:00:00', 'pix']]);
    seedExpense($w->ws, seedExpenseCategory($w->ws), ['spent_on' => '2026-11-05', 'amount_cents' => 800]);
    $this->loadOpenApi('FIN-003-dashboard-financeiro');

    expect($this->schemaErrors(expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->assertOk()->json(), 'FinanceSummary'))->toBe([]);
    $monthly = expApi($this, $w->token, 'GET', 'finance/monthly?to=2026-11')->assertOk();
    expect($this->schemaErrors($monthly->json(), 'FinanceMonthly'))->toBe([]);
    $calendar = expApi($this, $w->token, 'GET', 'finance/calendar?month=2026-11')->assertOk();
    expect($this->schemaErrors($calendar->json(), 'FinanceCalendar'))->toBe([]);
    expect($this->schemaErrors(expApi($this, $w->token, 'GET', 'finance/goal')->json(), 'FinanceGoal'))->toBe([]);
    expect($this->schemaErrors(expApi($this, $w->token, 'PUT', 'finance/goal', ['monthly_goal_cents' => 100000])->json(), 'FinanceGoal'))->toBe([]);
});

it('CA-14 returns the documented problem bodies', function () {
    $w = finWorld($this);
    $this->loadOpenApi('FIN-003-dashboard-financeiro');

    $invalid = expApi($this, $w->token, 'GET', 'finance/summary')->assertStatus(422);
    expect($this->schemaErrors($invalid->json(), 'Problem'))->toBe([]);
});
