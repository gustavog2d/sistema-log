<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

function moveTo($test, object $w, object $appointment, string $status)
{
    return apptApi($test, $w->token, 'PATCH', 'neg', "appointments/{$appointment->id}", ['status' => $status]);
}

it('CA-05 follows the allowed passages of status', function (string $from, string $to) {
    $w = apptWorld($this);
    $appointment = seedAppointment($w, ['status' => $from]);

    moveTo($this, $w, $appointment, $to)->assertOk()->assertJsonPath('status', $to);
})->with([
    ['pending', 'scheduled'], ['pending', 'canceled'],
    ['scheduled', 'completed'], ['scheduled', 'canceled'], ['scheduled', 'no_show'], ['scheduled', 'pending'],
    ['canceled', 'scheduled'], ['no_show', 'scheduled'],
]);

it('CA-05 refuses the other passages and keeps the status', function (string $from, string $to) {
    $w = apptWorld($this);
    $appointment = seedAppointment($w, ['status' => $from]);

    moveTo($this, $w, $appointment, $to)->assertStatus(422)->assertJsonValidationErrors('status');
    expect(DB::connection('pgsql_admin')->table('appointments')->where('id', $appointment->id)->value('status'))->toBe($from);
})->with([
    ['pending', 'completed'], ['pending', 'no_show'],
    ['completed', 'scheduled'], ['completed', 'canceled'], ['completed', 'pending'], ['completed', 'no_show'],
    ['canceled', 'completed'], ['canceled', 'pending'], ['canceled', 'no_show'],
    ['no_show', 'completed'], ['no_show', 'canceled'], ['no_show', 'pending'],
]);

it('CA-05 checks the conflict again when a canceled appointment is reactivated', function () {
    $w = apptWorld($this);
    $canceled = seedAppointment($w, ['status' => 'canceled']);
    $other = seedAppointment($w, ['starts_at' => '2026-11-03 13:30:00', 'ends_at' => '2026-11-03 14:30:00']);

    moveTo($this, $w, $canceled, 'scheduled')->assertStatus(409)->assertJsonPath('conflict.id', $other->id);
    DB::connection('pgsql_admin')->table('appointments')->where('id', $other->id)->delete();
    moveTo($this, $w, $canceled, 'scheduled')->assertOk();
});

it('CA-05 checks the working hours again when an appointment is reactivated, with the encaixe still available', function () {
    $w = apptWorld($this);
    $canceled = seedAppointment($w, ['status' => 'no_show', 'starts_at' => '2026-11-03 02:00:00', 'ends_at' => '2026-11-03 03:00:00']);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$canceled->id}", ['status' => 'scheduled'])->assertStatus(422)->assertJsonPath('code', 'outside_availability');
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$canceled->id}", ['status' => 'scheduled', 'allow_outside_hours' => true])->assertOk();
});

it('CA-06 lets a completed appointment change only notes, value and review', function () {
    $w = apptWorld($this);
    $done = seedAppointment($w, ['status' => 'completed']);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$done->id}", ['notes' => 'Cicatrizou bem', 'value_total_cents' => 20000, 'client_review' => 5])->assertOk()
        ->assertJsonPath('notes', 'Cicatrizou bem')->assertJsonPath('value_total_cents', 20000)->assertJsonPath('client_review', 5);

    foreach (['starts_at' => '2026-11-04T10:00:00-03:00', 'professional_id' => $w->pro->id, 'client_id' => $w->client->id, 'type' => 'retouch', 'description' => 'x', 'session_number' => 1] as $field => $value) {
        apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$done->id}", [$field => $value])->assertStatus(422)->assertJsonValidationErrors($field);
    }
});

it('CA-06 lets a canceled or no-show appointment change only the notes', function (string $status) {
    $w = apptWorld($this);
    $row = seedAppointment($w, ['status' => $status]);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$row->id}", ['notes' => 'Avisou que não vem'])->assertOk()->assertJsonPath('notes', 'Avisou que não vem');
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$row->id}", ['value_total_cents' => 1])->assertStatus(422)->assertJsonValidationErrors('value_total_cents');
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$row->id}", ['starts_at' => '2026-11-04T10:00:00-03:00'])->assertStatus(422)->assertJsonValidationErrors('starts_at');
})->with(['canceled', 'no_show']);

it('CA-06 takes the review only from 1 to 5 and only when completed', function () {
    $w = apptWorld($this);
    $done = seedAppointment($w, ['status' => 'completed']);
    $active = seedAppointment($w, ['starts_at' => '2026-11-04 13:00:00', 'ends_at' => '2026-11-04 14:00:00']);

    foreach ([0, 6, -1] as $review) {
        apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$done->id}", ['client_review' => $review])->assertStatus(422)->assertJsonValidationErrors('client_review');
    }
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$active->id}", ['client_review' => 4])->assertStatus(422)->assertJsonValidationErrors('client_review');
    // Finalizar e avaliar no mesmo pedido vale.
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$active->id}", ['status' => 'completed', 'client_review' => 4])->assertOk()->assertJsonPath('client_review', 4)->assertJsonPath('status', 'completed');
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$done->id}", ['client_review' => null])->assertOk()->assertJsonPath('client_review', null);
});

it('CA-06 edits an active appointment freely: move, change service and professional, keeping the checks', function () {
    $w = apptWorld($this);
    $other = seedProfessional($w->ws, ['name' => 'Bia']);
    seedHours($w->ws, $other, [[3, 480, 1200]]);
    $row = seedAppointment($w);
    $newService = seedService($w->ws, ['name' => 'Retoque', 'duration_minutes' => 30, 'price_cents' => 5000, 'buffer_after_minutes' => 5]);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$row->id}", ['professional_id' => $other->id, 'starts_at' => '2026-11-04T09:00:00-03:00', 'service_id' => $newService->id, 'ends_at' => '2026-11-04T09:30:00-03:00'])->assertOk()
        ->assertJsonPath('professional.name', 'Bia')->assertJsonPath('service.name', 'Retoque')->assertJsonPath('ends_at', '2026-11-04T12:30:00+00:00');

    $stored = DB::connection('pgsql_admin')->table('appointments')->where('id', $row->id)->first();
    expect($stored->buffer_after_minutes)->toBe(5)->and(Carbon::parse($stored->blocked_until, 'UTC')->toDateTimeString())->toBe('2026-11-04 12:35:00');
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$row->id}", ['professional_id' => $w->pro->id, 'starts_at' => '2026-11-04T09:00:00-03:00'])->assertOk();
});

it('CA-06 keeps the end following the duration when only the start of an appointment with a service changes', function () {
    $w = apptWorld($this);
    $created = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w))->assertCreated()->json();

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$created['id']}", ['starts_at' => '2026-11-03T15:00:00-03:00'])->assertOk()
        ->assertJsonPath('starts_at', '2026-11-03T18:00:00+00:00')->assertJsonPath('ends_at', '2026-11-03T19:00:00+00:00');
});

it('CA-10 deletes an active appointment but not a completed one', function () {
    $w = apptWorld($this);
    $active = seedAppointment($w);
    $done = seedAppointment($w, ['status' => 'completed', 'starts_at' => '2026-11-04 13:00:00', 'ends_at' => '2026-11-04 14:00:00']);

    apptApi($this, $w->token, 'DELETE', 'neg', "appointments/{$active->id}")->assertNoContent();
    apptApi($this, $w->token, 'DELETE', 'neg', "appointments/{$done->id}")->assertStatus(409)->assertJsonPath('code', 'appointment_completed');
    expect(apptCount())->toBe(1);
});
