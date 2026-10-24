<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

/** Novembro de 2026 com todos os casos: pagamento em outro mês, cancelado, falta, sem valor, estornado, despesas. */
function novemberBooks($test): object
{
    $w = finWorld($test);
    $a1 = finAppointment($w, '2026-11-03 13:00:00', 10000, 'completed', 40, [[4000, '2026-11-03 13:00:00', 'pix'], [6000, '2026-11-20 15:00:00', 'cash']]);
    seedPayment($w, $a1, ['amount_cents' => 1000, 'status' => 'voided', 'voided_at' => now(), 'paid_at' => '2026-11-04 12:00:00']);
    finAppointment($w, '2026-11-10 13:00:00', 20000, 'scheduled', 50, [[5000, '2026-10-28 15:00:00', 'pix']]);
    finAppointment($w, '2026-11-12 13:00:00', 99999, 'canceled');
    finAppointment($w, '2026-11-13 13:00:00', 7777, 'no_show');
    finAppointment($w, '2026-11-15 13:00:00', null, 'pending');
    finAppointment($w, '2026-12-05 13:00:00', 8000, 'scheduled', 25, [[3000, '2026-11-25 15:00:00', 'credit_card']]);
    $category = seedExpenseCategory($w->ws);
    seedExpense($w->ws, $category, ['spent_on' => '2026-11-05', 'amount_cents' => 3000]);
    seedExpense($w->ws, $category, ['spent_on' => '2026-12-01', 'amount_cents' => 2000]);

    return $w;
}

it('CA-01 sums only the payments received in the period, not voided, not from canceled appointments', function () {
    $w = novemberBooks($this);

    $summary = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->assertOk();

    // 4.000 + 6.000 (agendamento 1) + 3.000 (sinal pago em novembro de um atendimento de dezembro); o sinal de outubro e o estornado ficam de fora.
    expect($summary->json('received_cents'))->toBe(13000);
});

it('CA-02 expects the value of the active appointments of the period and what is still to receive', function () {
    $w = novemberBooks($this);

    $summary = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->assertOk();

    // Prevista: 10.000 + 20.000 + 0 (sem valor); cancelado e falta fora. A receber: 30.000 − (10.000 + 5.000 já recebidos desses atendimentos, inclusive o sinal de outubro).
    expect($summary->json('expected_cents'))->toBe(30000)->and($summary->json('appointments_count'))->toBe(3)->and($summary->json('to_receive_cents'))->toBe(15000);
});

it('CA-02 never shows a negative amount to receive', function () {
    $w = finWorld($this);
    finAppointment($w, '2026-11-03 13:00:00', 10000, 'scheduled', 40, [[10000, '2026-11-03 13:00:00', 'pix']]);
    // O valor combinado caiu depois do pagamento (direto no banco, só para provar o piso em zero).
    DB::connection('pgsql_admin')->statement('ALTER TABLE appointments DISABLE TRIGGER appointments_finance_guard');
    DB::connection('pgsql_admin')->table('appointments')->update(['value_total_cents' => 4000]);
    DB::connection('pgsql_admin')->statement('ALTER TABLE appointments ENABLE TRIGGER appointments_finance_guard');

    expect(expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->json('to_receive_cents'))->toBe(0);
});

it('CA-03 takes the commission with the percentage frozen in each appointment', function () {
    $w = novemberBooks($this);

    $summary = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->assertOk();

    // Recebida: 10.000 a 40% = 4.000 e 3.000 a 25% = 750 → comissão 4.750, líquida 8.250.
    // Prevista: 10.000 a 40% = 4.000 e 20.000 a 50% = 10.000 → comissão 14.000, líquida 16.000.
    expect($summary->json())->toMatchArray([
        'commission_received_cents' => 4750, 'net_received_cents' => 8250,
        'commission_expected_cents' => 14000, 'net_expected_cents' => 16000,
    ]);
});

it('CA-04 subtracts the expenses of the period to give the result, even when it is negative', function () {
    $w = novemberBooks($this);

    $november = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->assertOk();
    expect($november->json('expenses_cents'))->toBe(3000)->and($november->json('result_cents'))->toBe(5250);

    $december = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-12-01&to=2026-12-31')->assertOk();
    expect($december->json('expenses_cents'))->toBe(2000)->and($december->json('result_cents'))->toBe(-2000);
});

it('CA-05 groups the amount received by payment method, biggest first', function () {
    $w = novemberBooks($this);

    $by = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->json('by_method');

    expect($by)->toBe([['method' => 'cash', 'total_cents' => 6000], ['method' => 'pix', 'total_cents' => 4000], ['method' => 'credit_card', 'total_cents' => 3000]]);
});

it('CA-01 reads the day on the business clock, not on UTC', function () {
    $w = finWorld($this);
    finAppointment($w, '2026-12-01 02:30:00', 10000, 'scheduled', 0, [[1000, '2026-12-01 02:30:00', 'pix'], [2000, '2026-12-01 03:30:00', 'pix']]);

    // 02:30Z de 1º/12 ainda é 23:30 de 30/11 em Brasília; 03:30Z já é 00:30 de 1º/12.
    $november = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->assertOk();
    $december = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-12-01&to=2026-12-31')->assertOk();

    expect($november->json('received_cents'))->toBe(1000)->and($november->json('expected_cents'))->toBe(10000)
        ->and($december->json('received_cents'))->toBe(2000)->and($december->json('expected_cents'))->toBe(0);
});

it('CA-06 returns zeros for a period without movement and accepts one day and a whole year', function () {
    $w = finWorld($this);

    $empty = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-01-01&to=2026-12-31')->assertOk();
    expect($empty->json())->toBe([
        'received_cents' => 0, 'expected_cents' => 0, 'to_receive_cents' => 0, 'commission_received_cents' => 0, 'net_received_cents' => 0,
        'commission_expected_cents' => 0, 'net_expected_cents' => 0, 'expenses_cents' => 0, 'result_cents' => 0, 'appointments_count' => 0, 'by_method' => [],
    ]);
    expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-03&to=2026-11-03')->assertOk();
});

it('CA-06 refuses a missing, inverted, too long or invalid period', function (string $query, string $field) {
    $w = finWorld($this);

    expApi($this, $w->token, 'GET', 'finance/summary?'.$query)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['to=2026-11-30', 'from'],
    ['from=2026-11-01', 'to'],
    ['from=ontem&to=2026-11-30', 'from'],
    ['from=2026-11-30&to=2026-11-01', 'to'],
    ['from=2025-01-01&to=2026-11-30', 'to'],
    ['from=2026-02-30&to=2026-03-01', 'from'],
]);

it('CA-03 rounds the commission cent by cent in each payment, so the totals match the appointment screen', function () {
    $w = finWorld($this);
    // 3 pagamentos de R$ 10,05 a 15%: 150,75 → 151 centavos cada (453), e não 452 como um arredondamento só no total.
    foreach ([3, 4, 5] as $day) {
        finAppointment($w, "2026-11-0{$day} 13:00:00", 1005, 'scheduled', 15, [[1005, "2026-11-0{$day} 13:00:00", 'pix']]);
    }

    $summary = expApi($this, $w->token, 'GET', 'finance/summary?from=2026-11-01&to=2026-11-30')->assertOk();
    $monthly = expApi($this, $w->token, 'GET', 'finance/monthly?to=2026-11')->assertOk();

    expect($summary->json('commission_received_cents'))->toBe(453)->and($summary->json('commission_expected_cents'))->toBe(453)
        ->and($monthly->json('totals.net_received_cents'))->toBe($summary->json('net_received_cents'));
});
