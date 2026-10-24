<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-07 shows the amount received and the payment status of each appointment', function () {
    $w = apptWorld($this);
    $none = seedAppointment($w, ['value_total_cents' => null, 'starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);
    $unpaid = payAppointment($w, ['starts_at' => '2026-11-04 13:00:00', 'ends_at' => '2026-11-04 14:00:00']);
    $partial = payAppointment($w, ['starts_at' => '2026-11-05 13:00:00', 'ends_at' => '2026-11-05 14:00:00']);
    $paid = payAppointment($w, ['starts_at' => '2026-11-06 13:00:00', 'ends_at' => '2026-11-06 14:00:00']);
    seedPayment($w, $partial, ['amount_cents' => 5000]);
    seedPayment($w, $partial, ['amount_cents' => 4000, 'status' => 'voided', 'voided_at' => now()]);
    seedPayment($w, $paid, ['amount_cents' => 15000]);

    $list = collect(apptApi($this, $w->token, 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z')->assertOk()->json('data'))->keyBy('id');

    expect($list[$none->id])->toMatchArray(['received_cents' => 0, 'payment_status' => 'no_value'])
        ->and($list[$unpaid->id])->toMatchArray(['received_cents' => 0, 'payment_status' => 'unpaid'])
        ->and($list[$partial->id])->toMatchArray(['received_cents' => 5000, 'payment_status' => 'partial'])
        ->and($list[$paid->id])->toMatchArray(['received_cents' => 15000, 'payment_status' => 'paid']);
    apptApi($this, $w->token, 'GET', 'neg', "appointments/{$partial->id}")->assertJsonPath('received_cents', 5000)->assertJsonPath('payment_status', 'partial');
});

it('CA-07 hides the amount received and the payment status from the reception', function () {
    $w = apptTeam($this);
    seedPayment($w, $w->anaAppointment, ['amount_cents' => 5000]);

    $show = apptApi($this, $w->tokens['reception'], 'GET', 'neg', "appointments/{$w->anaAppointment->id}")->assertOk();

    expect($show->json('received_cents'))->toBeNull()->and($show->json('payment_status'))->toBeNull()
        ->and($show->json('commission_percent'))->toBeNull()->and($show->json('commission_cents'))->toBeNull();
});

it('CA-08 copies the commission of the professional when scheduling and freezes it', function () {
    $w = apptWorld($this);
    DB::connection('pgsql_admin')->table('professionals')->where('id', $w->pro->id)->update(['commission_percent' => 40]);

    $created = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w))->assertCreated();

    expect($created->json())->toMatchArray(['commission_percent' => 40, 'commission_cents' => 6000]);

    DB::connection('pgsql_admin')->table('professionals')->where('id', $w->pro->id)->update(['commission_percent' => 50]);
    apptApi($this, $w->token, 'GET', 'neg', 'appointments/'.$created->json('id'))->assertJsonPath('commission_percent', 40)->assertJsonPath('commission_cents', 6000);
});

it('CA-08 takes the commission of the new professional when the appointment changes professional, and rounds to the cent', function () {
    $w = apptWorld($this);
    $other = seedProfessional($w->ws, ['name' => 'Caio', 'commission_percent' => 15]);
    seedHours($w->ws, $other, array_map(fn (int $day) => [$day, 480, 1200], [0, 1, 2, 3, 4, 5, 6]));
    $created = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['value_total_cents' => 3333]))->assertCreated();
    expect($created->json('commission_percent'))->toBe(0);

    $moved = apptApi($this, $w->token, 'PATCH', 'neg', 'appointments/'.$created->json('id'), ['professional_id' => $other->id])->assertOk();

    // 3333 × 15% = 499,95 → 500
    expect($moved->json())->toMatchArray(['commission_percent' => 15, 'commission_cents' => 500]);
});

it('CA-09 shows the commission only to owner, manager and the professional who owns the appointment', function () {
    $w = apptTeam($this);
    DB::connection('pgsql_admin')->table('appointments')->where('id', $w->anaAppointment->id)->update(['commission_percent' => 40]);

    foreach (['owner', 'manager', 'ana'] as $who) {
        apptApi($this, $w->tokens[$who], 'GET', 'neg', "appointments/{$w->anaAppointment->id}")->assertJsonPath('commission_percent', 40)->assertJsonPath('commission_cents', 6000);
    }
    apptApi($this, $w->tokens['reception'], 'GET', 'neg', "appointments/{$w->anaAppointment->id}")->assertJsonPath('commission_percent', null);
});
