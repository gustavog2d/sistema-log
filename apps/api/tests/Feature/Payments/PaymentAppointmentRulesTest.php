<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-05 refuses to lower the combined value under what was received', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    seedPayment($w, $a, ['amount_cents' => 10000]);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$a->id}", ['value_total_cents' => 8000])->assertStatus(422)
        ->assertJsonPath('code', 'value_below_received')->assertJsonValidationErrors('value_total_cents');
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$a->id}", ['value_total_cents' => 10000])->assertOk()->assertJsonPath('value_total_cents', 10000);
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$a->id}", ['value_total_cents' => null])->assertStatus(422)->assertJsonPath('code', 'value_below_received');
});

it('CA-05 ignores voided payments when lowering the value', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    seedPayment($w, $a, ['amount_cents' => 10000, 'status' => 'voided', 'voided_at' => now()]);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$a->id}", ['value_total_cents' => 100])->assertOk();
});

it('CA-06 does not cancel or mark absence on an appointment with received payments, until they are voided', function (string $status) {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $p = seedPayment($w, $a);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$a->id}", ['status' => $status])->assertStatus(409)->assertJsonPath('code', 'appointment_has_payments');
    payApi($this, $w->token, 'POST', "payments/{$p->id}/void")->assertOk();
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$a->id}", ['status' => $status])->assertOk();
})->with(['canceled', 'no_show']);

it('CA-06 does not delete an appointment that has any payment record, even a voided one', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    seedPayment($w, $a, ['status' => 'voided', 'voided_at' => now()]);

    apptApi($this, $w->token, 'DELETE', 'neg', "appointments/{$a->id}")->assertStatus(409)->assertJsonPath('code', 'appointment_has_payments');
    expect(DB::connection('pgsql_admin')->table('appointments')->where('id', $a->id)->exists())->toBeTrue();
});

it('CA-06 keeps the database guard against deleting an appointment with payments', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    seedPayment($w, $a);

    expect(fn () => DB::connection('pgsql_admin')->table('appointments')->where('id', $a->id)->delete())->toThrow(QueryException::class);
});

it('CA-06 still lets an appointment without payments be deleted or canceled', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $b = payAppointment($w, ['starts_at' => '2026-11-04 13:00:00', 'ends_at' => '2026-11-04 14:00:00']);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$a->id}", ['status' => 'canceled'])->assertOk();
    apptApi($this, $w->token, 'DELETE', 'neg', "appointments/{$b->id}")->assertNoContent();
});
