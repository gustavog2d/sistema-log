<?php

use App\Appointments\Appointment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-02 refuses an overlapping appointment of the same professional and says which one is in the way', function () {
    $w = apptWorld($this);
    $existing = seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);

    $response = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T10:30:00-03:00']))->assertStatus(409)
        ->assertJsonPath('code', 'appointment_conflict')->assertJsonPath('conflict.id', $existing->id)
        ->assertJsonPath('conflict.starts_at', '2026-11-03T13:00:00+00:00');

    expect($response->json('conflict'))->not->toHaveKey('notes')->and(apptCount())->toBe(1);
});

it('CA-02 counts the buffers: the service leaves 10 free minutes after it', function () {
    $w = apptWorld($this);
    seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00', 'buffer_after_minutes' => 10]);

    // 14:05 cai dentro dos 10 minutos de intervalo depois do primeiro.
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T11:05:00-03:00']))->assertStatus(409);
    // 14:10 encosta exatamente no fim do intervalo: vale.
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T11:10:00-03:00']))->assertCreated();
});

it('CA-02 counts the buffer before the new appointment too', function () {
    $w = apptWorld($this);
    DB::connection('pgsql_admin')->table('services')->where('id', $w->service->id)->update(['buffer_before_minutes' => 15, 'buffer_after_minutes' => 0]);
    seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);

    // Começa às 14:05, mas precisa de 15 minutos livres antes: invade o fim do anterior.
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T11:05:00-03:00']))->assertStatus(409);
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T11:15:00-03:00']))->assertCreated();
});

it('CA-02 lets another professional have the same time and frees the slot of a canceled or no-show appointment', function () {
    $w = apptWorld($this);
    $other = seedProfessional($w->ws, ['name' => 'Bia']);
    seedHours($w->ws, $other, [[2, 480, 1200]]);
    seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $other->id]))->assertCreated();

    foreach (['canceled', 'no_show'] as $index => $status) {
        $day = 4 + $index;
        seedAppointment($w, ['starts_at' => "2026-11-0{$day} 13:00:00", 'ends_at' => "2026-11-0{$day} 14:00:00", 'status' => $status]);
        apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => "2026-11-0{$day}T10:00:00-03:00"]))->assertCreated();
    }
});

it('CA-02 refuses to move an appointment onto another one but lets it overlap itself', function () {
    $w = apptWorld($this);
    $first = seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);
    $second = seedAppointment($w, ['starts_at' => '2026-11-03 17:00:00', 'ends_at' => '2026-11-03 18:00:00']);

    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$second->id}", ['starts_at' => '2026-11-03T10:30:00-03:00', 'ends_at' => '2026-11-03T11:30:00-03:00'])->assertStatus(409)->assertJsonPath('conflict.id', $first->id);
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$first->id}", ['starts_at' => '2026-11-03T10:15:00-03:00', 'ends_at' => '2026-11-03T11:15:00-03:00'])->assertOk()->assertJsonPath('starts_at', '2026-11-03T13:15:00+00:00');
});

it('CA-03 lets the database refuse an overlapping row written directly, with and without the buffers', function () {
    $w = apptWorld($this);
    seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00', 'buffer_after_minutes' => 10]);

    expect(fn () => seedAppointment($w, ['starts_at' => '2026-11-03 13:30:00', 'ends_at' => '2026-11-03 14:30:00']))->toThrow(QueryException::class);
    expect(fn () => seedAppointment($w, ['starts_at' => '2026-11-03 14:05:00', 'ends_at' => '2026-11-03 15:00:00']))->toThrow(QueryException::class);
    seedAppointment($w, ['starts_at' => '2026-11-03 14:10:00', 'ends_at' => '2026-11-03 15:00:00']);
    expect(apptCount())->toBe(2);
});

it('CA-03 turns the database refusal of a race into the same 409', function () {
    $w = apptWorld($this);
    $inserted = false;

    // Entre a checagem de conflito e a gravação, outra pessoa agenda o mesmo horário.
    Appointment::creating(function () use (&$inserted, $w) {
        if (! $inserted) {
            $inserted = true;
            seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);
        }
    });

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w))->assertStatus(409)->assertJsonPath('code', 'appointment_conflict');
    expect($inserted)->toBeTrue()->and(apptCount())->toBe(1);
});
