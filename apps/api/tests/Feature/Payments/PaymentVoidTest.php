<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-04 voids a received payment once, keeping the amount and the record', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $p = seedPayment($w, $a);

    $voided = payApi($this, $w->token, 'POST', "payments/{$p->id}/void")->assertOk();

    expect($voided->json())->toMatchArray(['status' => 'voided', 'amount_cents' => 5000])->and($voided->json('voided_at'))->not->toBeNull();
    payApi($this, $w->token, 'POST', "payments/{$p->id}/void")->assertStatus(409)->assertJsonPath('code', 'payment_voided');
    expect(DB::connection('pgsql_admin')->table('payments')->count())->toBe(1);
    expect(payApi($this, $w->token, 'GET', "appointments/{$a->id}/payments")->json('summary'))->toMatchArray(['received_cents' => 0, 'payment_status' => 'unpaid']);
});

it('CA-04 corrects the type, the method and the date, but never the amount', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $p = seedPayment($w, $a);

    $response = payApi($this, $w->token, 'PATCH', "payments/{$p->id}", ['type' => 'installment', 'method' => 'cash', 'paid_at' => '2026-11-01T10:00:00-03:00', 'amount_cents' => 1])->assertOk();

    expect($response->json())->toMatchArray(['type' => 'installment', 'method' => 'cash', 'amount_cents' => 5000])
        ->and(Carbon::parse($response->json('paid_at'))->equalTo(Carbon::parse('2026-11-01T13:00:00Z')))->toBeTrue();
});

it('CA-04 validates the correction and refuses to edit a voided payment', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $p = seedPayment($w, $a);
    $gone = seedPayment($w, $a, ['status' => 'voided', 'voided_at' => now()]);

    payApi($this, $w->token, 'PATCH', "payments/{$p->id}", ['method' => 'x'])->assertStatus(422)->assertJsonValidationErrors('method');
    payApi($this, $w->token, 'PATCH', "payments/{$p->id}", ['paid_at' => '2026-11-02T12:10:00Z'])->assertStatus(422)->assertJsonValidationErrors('paid_at');
    payApi($this, $w->token, 'PATCH', "payments/{$gone->id}", ['method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'payment_voided');
});

it('CA-11 records the registration, the correction and the void in the history with before and after', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);

    $id = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody())->assertCreated()->json('id');
    payApi($this, $w->token, 'PATCH', "payments/{$id}", ['method' => 'cash'])->assertOk();
    payApi($this, $w->token, 'POST', "payments/{$id}/void")->assertOk();

    $logs = DB::connection('pgsql_admin')->table('audit_logs')->where('entity_type', 'payment')->where('entity_id', $id)->orderBy('created_at')->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['created', 'updated', 'updated']);
    $last = json_decode((string) $logs->last()->after, true);
    $before = json_decode((string) $logs->last()->before, true);
    expect($before['status'])->toBe('received')->and($last['status'])->toBe('voided');
});
