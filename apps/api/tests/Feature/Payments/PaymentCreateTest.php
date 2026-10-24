<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-01 registers a payment with the amount, type and method and shows it in the list with the summary', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);

    $created = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody())->assertCreated();

    expect($created->json())->toMatchArray(['appointment_id' => $a->id, 'type' => 'deposit', 'method' => 'pix', 'amount_cents' => 5000, 'status' => 'received', 'voided_at' => null]);
    $list = payApi($this, $w->token, 'GET', "appointments/{$a->id}/payments")->assertOk();
    expect($list->json('data'))->toHaveCount(1)
        ->and($list->json('summary'))->toMatchArray(['total_cents' => 15000, 'received_cents' => 5000, 'remaining_cents' => 10000, 'payment_status' => 'partial']);
});

it('CA-01 uses now as the payment date when none is sent and accepts up to five minutes ahead', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);

    $now = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody(['amount_cents' => 1000]))->assertCreated();
    expect(Carbon::parse($now->json('paid_at'))->equalTo(now()))->toBeTrue();
    payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody(['amount_cents' => 1000, 'paid_at' => now()->addMinutes(4)->toIso8601String()]))->assertCreated();
});

it('CA-01 validates the fields', function (array $override, string $field) {
    $w = apptWorld($this);
    $a = payAppointment($w);

    payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody($override))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    [['type' => 'x'], 'type'],
    [['method' => 'boleto'], 'method'],
    [['amount_cents' => 0], 'amount_cents'],
    [['amount_cents' => -5], 'amount_cents'],
    [['amount_cents' => 'dez'], 'amount_cents'],
    [['amount_cents' => 10.5], 'amount_cents'],
    [['amount_cents' => 100000001], 'amount_cents'],
    [['paid_at' => 'ontem'], 'paid_at'],
    [['paid_at' => '2026-11-02T12:10:00Z'], 'paid_at'],
]);

it('CA-01 refuses a missing type, method or amount', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);

    payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", [])->assertStatus(422)->assertJsonValidationErrors(['type', 'method', 'amount_cents']);
});

it('CA-01 takes payments for pending, scheduled and completed appointments only', function (string $status, int $expected, ?string $code) {
    $w = apptWorld($this);
    $a = payAppointment($w, ['status' => $status]);

    $response = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody())->assertStatus($expected);
    if ($code !== null) {
        $response->assertJsonPath('code', $code);
    }
})->with([
    ['pending', 201, null], ['scheduled', 201, null], ['completed', 201, null],
    ['canceled', 409, 'appointment_closed'], ['no_show', 409, 'appointment_closed'],
]);

it('CA-01 refuses an appointment without a combined value', function (?int $value) {
    $w = apptWorld($this);
    $a = seedAppointment($w, ['value_total_cents' => $value]);

    payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody())->assertStatus(422)->assertJsonPath('code', 'appointment_without_value');
})->with([[null], [0]]);

it('CA-12 never reaches an appointment or a payment of another business', function () {
    $a = apptWorld($this, 'negocioa');
    $b = apptWorld($this, 'negociob');
    $other = payAppointment($b);
    $payment = seedPayment($b, $other);

    payApi($this, $a->token, 'POST', "appointments/{$other->id}/payments", payBody(), null, 'negocioa')->assertNotFound();
    payApi($this, $a->token, 'GET', "appointments/{$other->id}/payments", [], null, 'negocioa')->assertNotFound();
    payApi($this, $a->token, 'PATCH', "payments/{$payment->id}", ['method' => 'cash'], null, 'negocioa')->assertNotFound();
    payApi($this, $a->token, 'POST', "payments/{$payment->id}/void", [], null, 'negocioa')->assertNotFound();
    expect(DB::connection('pgsql_admin')->table('payments')->where('id', $payment->id)->value('status'))->toBe('received');
});

it('CA-10 does not duplicate the payment when the same key is repeated', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);

    $first = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody(), 'chave-1')->assertCreated();
    $again = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody(), 'chave-1')->assertCreated();

    expect($again->json('id'))->toBe($first->json('id'))->and(DB::connection('pgsql_admin')->table('payments')->count())->toBe(1);
});

it('CA-10 asks for the idempotency key', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);

    $this->withToken($w->token)->postJson("http://neg.app.test/api/v1/appointments/{$a->id}/payments", payBody())->assertStatus(422)->assertJsonValidationErrors('Idempotency-Key');
});
