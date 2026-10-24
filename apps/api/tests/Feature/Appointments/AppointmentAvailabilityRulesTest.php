<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-04 accepts an appointment that fits one range of the working hours (business clock)', function () {
    $w = apptWorld($this);

    // 08:00 a 20:00 em Brasília: 08:00-09:00 cabe e 19:00-20:00 também.
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T08:00:00-03:00']))->assertCreated();
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T19:00:00-03:00']))->assertCreated();
});

it('CA-04 refuses outside the working hours and says why, unless the encaixe is confirmed', function (string $start) {
    $w = apptWorld($this);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => $start]))->assertStatus(422)
        ->assertJsonPath('code', 'outside_availability')->assertJsonPath('reasons', ['outside_working_hours']);
    expect(apptCount())->toBe(0);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => $start, 'allow_outside_hours' => true]))->assertCreated();
    expect(apptCount())->toBe(1);
})->with([
    'before opening' => ['2026-11-03T07:00:00-03:00'],
    'straddling the closing time' => ['2026-11-03T19:30:00-03:00'],
    'after closing' => ['2026-11-03T21:00:00-03:00'],
]);

it('CA-04 uses the business clock, not UTC, to read the working hours', function () {
    $w = apptWorld($this);

    // 22:00Z já é 19:00 em Brasília (dentro); 11:00Z é 08:00 (dentro); 10:00Z é 07:00 (fora).
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T22:00:00+00:00']))->assertCreated();
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T11:00:00+00:00']))->assertCreated();
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-04T10:00:00+00:00']))->assertStatus(422)->assertJsonPath('reasons', ['outside_working_hours']);
});

it('CA-04 refuses a day without working hours and an appointment that crosses midnight', function () {
    $w = apptWorld($this);
    $weekday = seedProfessional($w->ws, ['name' => 'Só terças']);
    seedHours($w->ws, $weekday, [[2, 540, 720], [2, 840, 1080]]);

    // Quarta-feira: sem expediente.
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $weekday->id, 'starts_at' => '2026-11-04T10:00:00-03:00']))->assertStatus(422)->assertJsonPath('reasons', ['outside_working_hours']);
    // Terça no intervalo do almoço (12:00-14:00): entre duas faixas.
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $weekday->id, 'starts_at' => '2026-11-03T12:30:00-03:00']))->assertStatus(422);
    // Cabe em uma faixa só: 14:00-15:00 e 09:00-10:00.
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $weekday->id, 'starts_at' => '2026-11-03T14:00:00-03:00']))->assertCreated();
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $weekday->id, 'starts_at' => '2026-11-03T09:00:00-03:00']))->assertCreated();
    // Atravessa a meia-noite (de terça para quarta): nunca cabe em uma faixa.
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['service_id' => null, 'starts_at' => '2026-11-03T23:00:00-03:00', 'ends_at' => '2026-11-04T01:00:00-03:00']))->assertStatus(422);
});

it('CA-04 refuses the professional without any working hours', function () {
    $w = apptWorld($this);
    $none = seedProfessional($w->ws, ['name' => 'Sem expediente']);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $none->id]))->assertStatus(422)->assertJsonPath('code', 'outside_availability');
});

it('CA-04 refuses during a time off of the professional or of the whole business, with the right reason', function () {
    $w = apptWorld($this);
    seedTimeOff($w->ws, ['professional_id' => $w->pro->id, 'starts_at' => '2026-11-03 12:00:00', 'ends_at' => '2026-11-03 15:00:00']);
    seedTimeOff($w->ws, ['professional_id' => null, 'starts_at' => '2026-11-05 00:00:00', 'ends_at' => '2026-11-06 00:00:00']);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w))->assertStatus(422)->assertJsonPath('reasons', ['time_off']);
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-05T10:00:00-03:00']))->assertStatus(422)->assertJsonPath('reasons', ['time_off']);
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T18:00:00-03:00']))->assertCreated();
});

it('CA-04 reports both reasons when it is outside the hours and on a time off', function () {
    $w = apptWorld($this);
    seedTimeOff($w->ws, ['professional_id' => $w->pro->id, 'starts_at' => '2026-11-03 09:00:00', 'ends_at' => '2026-11-03 12:00:00']);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T07:00:00-03:00']))->assertStatus(422)->assertJsonPath('reasons', ['outside_working_hours', 'time_off']);
});

it('CA-04 never lets the encaixe override a conflict with another appointment', function () {
    $w = apptWorld($this);
    seedAppointment($w, ['starts_at' => '2026-11-03 10:00:00', 'ends_at' => '2026-11-03 11:00:00']);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T07:30:00-03:00', 'allow_outside_hours' => true]))->assertStatus(409);
});

it('CA-04 checks the rules again when an appointment is moved', function () {
    $w = apptWorld($this);
    $one = seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$one->id}", ['starts_at' => '2026-11-03T22:00:00-03:00', 'ends_at' => '2026-11-03T23:00:00-03:00'])->assertStatus(422)->assertJsonPath('code', 'outside_availability');
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$one->id}", ['starts_at' => '2026-11-03T22:00:00-03:00', 'ends_at' => '2026-11-03T23:00:00-03:00', 'allow_outside_hours' => true])->assertOk();
    // Mudar só as notas não confere o horário de novo (o encaixe já foi aceito).
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$one->id}", ['notes' => 'ok'])->assertOk();
});
