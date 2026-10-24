<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-02 never lets the received total pass the combined value, and tells how much is left', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $url = "appointments/{$a->id}/payments";

    payApi($this, $w->token, 'POST', $url, payBody(['amount_cents' => 5000]))->assertCreated();
    payApi($this, $w->token, 'POST', $url, payBody(['type' => 'final', 'amount_cents' => 10001]))->assertStatus(422)
        ->assertJsonPath('code', 'payment_exceeds_total')->assertJsonPath('remaining_cents', 10000);
    payApi($this, $w->token, 'POST', $url, payBody(['type' => 'final', 'amount_cents' => 10000]))->assertCreated();
    payApi($this, $w->token, 'POST', $url, payBody(['amount_cents' => 1]))->assertStatus(422)->assertJsonPath('remaining_cents', 0);
    expect(payApi($this, $w->token, 'GET', $url)->json('summary.payment_status'))->toBe('paid');
});

it('CA-02 gives the balance back when a payment is voided', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $first = seedPayment($w, $a, ['amount_cents' => 15000]);

    payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody())->assertStatus(422);
    payApi($this, $w->token, 'POST', "payments/{$first->id}/void")->assertOk();
    payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody(['amount_cents' => 15000]))->assertCreated();
});

it('CA-03 keeps the limit in the database too, so two writes at the same time cannot pass it', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    seedPayment($w, $a, ['amount_cents' => 10000]);

    expect(fn () => seedPayment($w, $a, ['amount_cents' => 5001]))->toThrow(QueryException::class);
    seedPayment($w, $a, ['amount_cents' => 5000]);
    expect(DB::connection('pgsql_admin')->table('payments')->count())->toBe(2);
    // Estornado não conta no limite.
    seedPayment($w, $a, ['amount_cents' => 99999, 'status' => 'voided', 'voided_at' => now()]);
});

it('CA-03 refuses at the database a payment for an appointment without value and a payment of another business', function () {
    $w = apptWorld($this);
    $b = apptWorld($this, 'negociob');
    $noValue = seedAppointment($w, ['value_total_cents' => null]);
    $other = payAppointment($b);

    expect(fn () => seedPayment($w, $noValue))->toThrow(QueryException::class)
        ->and(fn () => seedPayment($w, $other))->toThrow(QueryException::class);
});

it('CA-03 refuses at the database invalid types, methods, amounts and statuses', function (array $override) {
    $w = apptWorld($this);
    $a = payAppointment($w);

    expect(fn () => seedPayment($w, $a, $override))->toThrow(QueryException::class);
})->with([
    [['type' => 'x']], [['method' => 'x']], [['amount_cents' => 0]], [['status' => 'x']],
]);
