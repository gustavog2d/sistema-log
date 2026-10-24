<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-01 creates an appointment with the end by the service duration, the price and the buffers of the service', function () {
    $w = apptWorld($this);

    $response = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w))->assertCreated()
        ->assertJsonPath('starts_at', '2026-11-03T13:00:00+00:00')->assertJsonPath('ends_at', '2026-11-03T14:00:00+00:00')
        ->assertJsonPath('status', 'scheduled')->assertJsonPath('type', 'appointment')->assertJsonPath('origin', 'panel')
        ->assertJsonPath('value_total_cents', 15000)->assertJsonPath('client.name', 'Bia Lima')->assertJsonPath('professional.name', 'Ana Tattoo')
        ->assertJsonPath('service.name', 'Tatuagem pequena')->assertJsonPath('session_number', null);

    $row = DB::connection('pgsql_admin')->table('appointments')->where('id', $response->json('id'))->first();
    expect($row->buffer_after_minutes)->toBe(10)
        ->and(Carbon::parse($row->blocked_until, 'UTC')->toDateTimeString())->toBe('2026-11-03 14:10:00')
        ->and(Carbon::parse($row->blocked_from, 'UTC')->toDateTimeString())->toBe('2026-11-03 13:00:00');
});

it('CA-01 creates without a service using the given end and value, with every optional field', function () {
    $w = apptWorld($this);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, [
        'service_id' => null, 'ends_at' => '2026-11-03T12:30:00-03:00', 'description' => 'Fechamento de braço', 'type' => 'retouch',
        'session_number' => 2, 'sessions_total' => 5, 'value_total_cents' => 80000, 'notes' => 'Trazer referência', 'status' => 'pending',
    ]))->assertCreated()->assertJsonPath('ends_at', '2026-11-03T15:30:00+00:00')->assertJsonPath('service', null)->assertJsonPath('type', 'retouch')
        ->assertJsonPath('session_number', 2)->assertJsonPath('sessions_total', 5)->assertJsonPath('value_total_cents', 80000)
        ->assertJsonPath('notes', 'Trazer referência')->assertJsonPath('status', 'pending');
});

it('CA-01 refuses invalid data with the error on the field', function (array $override, string $field) {
    $w = apptWorld($this);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, $override))->assertStatus(422)->assertJsonValidationErrors($field);
    expect(apptCount())->toBe(0);
})->with([
    'no client' => [['client_id' => null], 'client_id'],
    'client not a uuid' => [['client_id' => 'x'], 'client_id'],
    'unknown client' => [['client_id' => '0194a7c0-0000-7000-8000-000000000000'], 'client_id'],
    'no professional' => [['professional_id' => null], 'professional_id'],
    'unknown professional' => [['professional_id' => '0194a7c0-0000-7000-8000-000000000000'], 'professional_id'],
    'unknown service' => [['service_id' => '0194a7c0-0000-7000-8000-000000000000'], 'service_id'],
    'no start' => [['starts_at' => null], 'starts_at'],
    'bad start' => [['starts_at' => 'amanhã'], 'starts_at'],
    'start not multiple of 5' => [['starts_at' => '2026-11-03T10:03:00-03:00'], 'starts_at'],
    'no end and no service' => [['service_id' => null], 'ends_at'],
    'end before start' => [['ends_at' => '2026-11-03T09:00:00-03:00'], 'ends_at'],
    'longer than 24 hours' => [['ends_at' => '2026-11-05T10:00:00-03:00'], 'ends_at'],
    'short description' => [['description' => str_repeat('a', 256)], 'description'],
    'bad status' => [['status' => 'x'], 'status'],
    'bad type' => [['type' => 'x'], 'type'],
    'session above total' => [['session_number' => 3, 'sessions_total' => 2], 'session_number'],
    'negative value' => [['value_total_cents' => -1], 'value_total_cents'],
    'long notes' => [['notes' => str_repeat('a', 2001)], 'notes'],
    'review without completing' => [['client_review' => 5], 'client_review'],
    'notes with control character' => [['notes' => "a\x00b"], 'notes'],
]);

it('CA-01 refuses an inactive professional, an archived service and anything of another business', function () {
    $w = apptWorld($this);
    $inactive = seedProfessional($w->ws, ['name' => 'Inativa', 'status' => 'inactive']);
    $archived = seedService($w->ws, ['name' => 'Arquivado', 'status' => 'archived']);
    [$b] = ownerOf($this, 'negociob');
    $foreignClient = seedClient($b);
    $foreignPro = seedProfessional($b);
    $foreignService = seedService($b);

    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $inactive->id]))->assertStatus(422)->assertJsonValidationErrors('professional_id');
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['service_id' => $archived->id]))->assertStatus(422)->assertJsonValidationErrors('service_id');
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['client_id' => $foreignClient->id]))->assertStatus(422)->assertJsonValidationErrors('client_id');
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $foreignPro->id]))->assertStatus(422)->assertJsonValidationErrors('professional_id');
    apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['service_id' => $foreignService->id]))->assertStatus(422)->assertJsonValidationErrors('service_id');
    expect(apptCount())->toBe(0);
});

it('CA-01 does not let the database link an appointment to a client, professional or service of another business', function () {
    $w = apptWorld($this);
    [$b] = ownerOf($this, 'negociob');

    expect(fn () => seedAppointment($w, ['client_id' => seedClient($b)->id]))->toThrow(QueryException::class);
    expect(fn () => seedAppointment($w, ['professional_id' => seedProfessional($b)->id]))->toThrow(QueryException::class);
    expect(fn () => seedAppointment($w, ['service_id' => seedService($b)->id]))->toThrow(QueryException::class);
});

it('CA-13 shows, edits and deletes only appointments of the own business', function () {
    $a = apptWorld($this, 'negocioa');
    $b = apptWorld($this, 'negociob');
    $mine = seedAppointment($a);
    $theirs = seedAppointment($b);

    apptApi($this, $a->token, 'GET', 'negocioa', "appointments/{$mine->id}")->assertOk();
    apptApi($this, $a->token, 'GET', 'negocioa', "appointments/{$theirs->id}")->assertNotFound();
    apptApi($this, $a->token, 'PATCH', 'negocioa', "appointments/{$theirs->id}", ['notes' => 'x'])->assertNotFound();
    apptApi($this, $a->token, 'DELETE', 'negocioa', "appointments/{$theirs->id}")->assertNotFound();
    apptApi($this, $a->token, 'GET', 'negocioa', 'appointments/nao-e-uuid')->assertNotFound();
    apptApi($this, $a->token, 'POST', 'negocioa', 'appointments', apptPayload($b))->assertStatus(422);
    expect(DB::connection('pgsql_admin')->table('appointments')->where('id', $theirs->id)->value('status'))->toBe('scheduled');
});

it('CA-12 records creation, edition and deletion in the history with the notes hidden', function () {
    $w = apptWorld($this);

    $id = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['notes' => 'Alergia a látex']))->assertCreated()->json('id');
    apptApi($this, $w->token, 'PATCH', 'neg', "appointments/{$id}", ['value_total_cents' => 17000, 'notes' => 'Outra nota de saúde'])->assertOk();
    apptApi($this, $w->token, 'DELETE', 'neg', "appointments/{$id}")->assertNoContent();

    $rows = auditRows();
    expect(collect($rows)->map(fn ($r) => $r->entity_type.':'.$r->action)->all())->toBe(['appointment:created', 'appointment:updated', 'appointment:deleted'])
        ->and(json_encode($rows))->not->toContain('látex')->not->toContain('saúde')
        ->and($rows[0]->after['notes'])->toBe('[oculto]')
        ->and($rows[1]->before['value_total_cents'])->toBe(15000)->and($rows[1]->after['value_total_cents'])->toBe(17000)
        ->and($rows[0]->after['client_id'])->toBe($w->client->id)->and($rows[2]->before['status'])->toBe('scheduled');
});
