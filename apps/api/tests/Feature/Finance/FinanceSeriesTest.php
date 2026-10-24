<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-08 gives twelve months ending at the chosen month, oldest first, with the empty ones', function () {
    $w = finWorld($this);
    finAppointment($w, '2026-05-12 13:00:00', 20000, 'scheduled', 50, [[5000, '2026-05-12 13:00:00', 'pix']]);
    finAppointment($w, '2026-11-03 13:00:00', 10000, 'completed', 40, [[10000, '2026-11-03 13:00:00', 'pix']]);
    seedExpense($w->ws, seedExpenseCategory($w->ws), ['spent_on' => '2026-05-20', 'amount_cents' => 1500]);
    seedExpense($w->ws, seedExpenseCategory($w->ws), ['spent_on' => '2025-12-31', 'amount_cents' => 700]);
    seedExpense($w->ws, seedExpenseCategory($w->ws), ['spent_on' => '2025-11-30', 'amount_cents' => 999]);

    $response = expApi($this, $w->token, 'GET', 'finance/monthly?to=2026-11')->assertOk();
    $months = collect($response->json('months'))->keyBy('month');

    expect($response->json('months'))->toHaveCount(12)->and($response->json('months.0.month'))->toBe('2025-12')->and($response->json('months.11.month'))->toBe('2026-11')
        ->and($months['2026-05'])->toMatchArray(['received_cents' => 5000, 'net_received_cents' => 2500, 'expected_cents' => 20000, 'expenses_cents' => 1500, 'result_cents' => 1000])
        ->and($months['2026-11'])->toMatchArray(['received_cents' => 10000, 'net_received_cents' => 6000, 'expected_cents' => 10000, 'expenses_cents' => 0, 'result_cents' => 6000])
        ->and($months['2025-12'])->toMatchArray(['received_cents' => 0, 'expenses_cents' => 700, 'result_cents' => -700])
        ->and($months['2026-03'])->toMatchArray(['received_cents' => 0, 'expected_cents' => 0, 'expenses_cents' => 0, 'result_cents' => 0]);
    // Novembro de 2025 fica fora da janela.
    expect($response->json('totals'))->toBe(['received_cents' => 15000, 'net_received_cents' => 8500, 'expected_cents' => 30000, 'expenses_cents' => 2200, 'result_cents' => 6300]);
});

it('CA-08 uses the current month when none is given and refuses an invalid one', function () {
    $w = finWorld($this);

    // O relógio do teste está em 02/11/2026.
    expect(expApi($this, $w->token, 'GET', 'finance/monthly')->assertOk()->json('months.11.month'))->toBe('2026-11');
    foreach (['2026-13', '2026-1', 'novembro', '2026-11-01', '1999-01', '2101-01'] as $bad) {
        expApi($this, $w->token, 'GET', 'finance/monthly?to='.$bad)->assertStatus(422)->assertJsonValidationErrors('to');
    }
});

it('CA-09 gives every day of the month with the movement of each local day', function () {
    $w = finWorld($this);
    finAppointment($w, '2026-11-03 13:00:00', 10000, 'completed', 40, [[4000, '2026-11-03 13:00:00', 'pix'], [6000, '2026-12-01 02:30:00', 'cash']]);
    finAppointment($w, '2026-11-03 17:00:00', 5000, 'scheduled');
    finAppointment($w, '2026-11-04 13:00:00', 9999, 'canceled');
    seedExpense($w->ws, seedExpenseCategory($w->ws), ['spent_on' => '2026-11-03', 'amount_cents' => 800]);

    $calendar = expApi($this, $w->token, 'GET', 'finance/calendar?month=2026-11')->assertOk();
    $days = collect($calendar->json('days'))->keyBy('date');

    expect($calendar->json('month'))->toBe('2026-11')->and($calendar->json('days'))->toHaveCount(30)
        ->and($calendar->json('days.0.date'))->toBe('2026-11-01')->and($calendar->json('days.29.date'))->toBe('2026-11-30')
        ->and($days['2026-11-03'])->toMatchArray(['received_cents' => 4000, 'expected_cents' => 15000, 'appointments_count' => 2, 'expenses_cents' => 800])
        ->and($days['2026-11-30'])->toMatchArray(['received_cents' => 6000, 'expected_cents' => 0, 'appointments_count' => 0])
        ->and($days['2026-11-04'])->toMatchArray(['received_cents' => 0, 'expected_cents' => 0, 'appointments_count' => 0, 'expenses_cents' => 0]);
});

it('CA-09 knows the length of each month and refuses a missing or invalid month', function () {
    $w = finWorld($this);

    expect(expApi($this, $w->token, 'GET', 'finance/calendar?month=2028-02')->json('days'))->toHaveCount(29)
        ->and(expApi($this, $w->token, 'GET', 'finance/calendar?month=2026-02')->json('days'))->toHaveCount(28);
    foreach (['', '?month=2026-13', '?month=x', '?month=2026-11-01'] as $query) {
        expApi($this, $w->token, 'GET', 'finance/calendar'.$query)->assertStatus(422)->assertJsonValidationErrors('month');
    }
});
