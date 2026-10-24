<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-07 creates a time off for a professional and one for the whole business', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);

    profApi($this, $token, 'POST', 'neg', 'time-offs', timeOffPayload(['professional_id' => $professional->id]))->assertCreated()
        ->assertJsonPath('professional_id', $professional->id)->assertJsonPath('reason', 'Recesso')
        ->assertJsonPath('starts_at', '2026-12-24T03:00:00+00:00')->assertJsonPath('ends_at', '2026-12-26T03:00:00+00:00');
    profApi($this, $token, 'POST', 'neg', 'time-offs', timeOffPayload(['reason' => null]))->assertCreated()
        ->assertJsonPath('professional_id', null)->assertJsonPath('reason', null);
    expect(DB::connection('pgsql_admin')->table('time_offs')->count())->toBe(2);
});

it('CA-07 refuses invalid time offs', function (array $payload, string $field) {
    [, $token] = ownerOf($this, 'neg');

    profApi($this, $token, 'POST', 'neg', 'time-offs', $payload + timeOffPayload())->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no start' => [['starts_at' => null], 'starts_at'],
    'bad start' => [['starts_at' => 'ontem'], 'starts_at'],
    'end before start' => [['ends_at' => '2026-12-20T00:00:00-03:00'], 'ends_at'],
    'end equal to start' => [['ends_at' => '2026-12-24T00:00:00-03:00'], 'ends_at'],
    'longer than 366 days' => [['ends_at' => '2028-01-01T00:00:00-03:00'], 'ends_at'],
    'long reason' => [['reason' => str_repeat('a', 101)], 'reason'],
    'reason with control' => [['reason' => "a\x00b"], 'reason'],
    'professional not a uuid' => [['professional_id' => 'x'], 'professional_id'],
    'unknown professional' => [['professional_id' => '0194a7c0-0000-7000-8000-000000000000'], 'professional_id'],
]);

it('CA-07 refuses a professional of another business', function () {
    [, $token] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $theirs = seedProfessional($b);

    profApi($this, $token, 'POST', 'negocioa', 'time-offs', timeOffPayload(['professional_id' => $theirs->id]))->assertStatus(422)->assertJsonValidationErrors('professional_id');
});

it('CA-07 edits, moves to the business as a whole and deletes', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);
    $off = seedTimeOff($ws, ['professional_id' => $professional->id]);

    profApi($this, $token, 'PATCH', 'neg', "time-offs/{$off->id}", ['reason' => 'Férias', 'professional_id' => null])->assertOk()->assertJsonPath('reason', 'Férias')->assertJsonPath('professional_id', null);
    profApi($this, $token, 'PATCH', 'neg', "time-offs/{$off->id}", ['ends_at' => '2026-12-20T00:00:00Z'])->assertStatus(422)->assertJsonValidationErrors('ends_at');
    profApi($this, $token, 'DELETE', 'neg', "time-offs/{$off->id}")->assertNoContent();
    expect(DB::connection('pgsql_admin')->table('time_offs')->count())->toBe(0);
});

it('CA-07 lists by start with filters by person and by period, and a cursor', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $ana = seedProfessional($ws, ['name' => 'Ana']);
    $bia = seedProfessional($ws, ['name' => 'Bia']);
    seedTimeOff($ws, ['professional_id' => $ana->id, 'starts_at' => '2026-12-01 00:00:00', 'ends_at' => '2026-12-05 00:00:00', 'reason' => 'ana-dez']);
    seedTimeOff($ws, ['professional_id' => $bia->id, 'starts_at' => '2026-12-10 00:00:00', 'ends_at' => '2026-12-12 00:00:00', 'reason' => 'bia-dez']);
    seedTimeOff($ws, ['professional_id' => null, 'starts_at' => '2026-12-24 00:00:00', 'ends_at' => '2026-12-26 00:00:00', 'reason' => 'natal']);
    seedTimeOff($ws, ['professional_id' => $ana->id, 'starts_at' => '2027-01-10 00:00:00', 'ends_at' => '2027-01-20 00:00:00', 'reason' => 'ana-jan']);

    $reasons = fn (string $query) => collect(profApi($this, $token, 'GET', 'neg', 'time-offs'.$query)->assertOk()->json('data'))->pluck('reason')->all();

    expect($reasons(''))->toBe(['ana-dez', 'bia-dez', 'natal', 'ana-jan']);
    // Uma pessoa vê as folgas dela e as do negócio todo.
    expect($reasons("?professional_id={$ana->id}"))->toBe(['ana-dez', 'natal', 'ana-jan']);
    expect($reasons('?from=2026-12-04T00:00:00Z&to=2026-12-11T00:00:00Z'))->toBe(['ana-dez', 'bia-dez']);
    expect($reasons('?from=2026-12-26T00:00:00Z'))->toBe(['ana-jan']);

    $first = profApi($this, $token, 'GET', 'neg', 'time-offs?limit=2')->assertOk();
    $second = profApi($this, $token, 'GET', 'neg', 'time-offs?limit=2&cursor='.urlencode($first->json('next_cursor')))->assertOk();
    expect(collect($second->json('data'))->pluck('reason')->all())->toBe(['natal', 'ana-jan'])->and($second->json('next_cursor'))->toBeNull();
});

it('CA-07 refuses invalid filters', function (string $query, string $field) {
    [, $token] = ownerOf($this, 'neg');

    profApi($this, $token, 'GET', 'neg', 'time-offs?'.$query)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([['professional_id=x', 'professional_id'], ['from=ontem', 'from'], ['to=amanhã', 'to'], ['limit=0', 'limit'], ['limit=51', 'limit']]);

it('CA-09 edits and deletes only time offs of the own business and never lists the others', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $mine = seedTimeOff($a);
    $theirs = seedTimeOff($b);

    profApi($this, $tokenA, 'PATCH', 'negocioa', "time-offs/{$theirs->id}", ['reason' => 'x'])->assertNotFound();
    profApi($this, $tokenA, 'DELETE', 'negocioa', "time-offs/{$theirs->id}")->assertNotFound();
    expect(collect(profApi($this, $tokenA, 'GET', 'negocioa', 'time-offs')->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

it('CA-08 records creation, edition and deletion of time offs in the history', function () {
    [, $token] = ownerOf($this, 'neg');

    $id = profApi($this, $token, 'POST', 'neg', 'time-offs', timeOffPayload())->assertCreated()->json('id');
    profApi($this, $token, 'PATCH', 'neg', "time-offs/{$id}", ['reason' => 'Feriado'])->assertOk();
    profApi($this, $token, 'DELETE', 'neg', "time-offs/{$id}")->assertNoContent();

    $rows = auditRows();
    expect(collect($rows)->map(fn ($r) => $r->entity_type.':'.$r->action)->all())->toBe(['time_off:created', 'time_off:updated', 'time_off:deleted'])
        ->and($rows[1]->before)->toBe(['reason' => 'Recesso'])->and($rows[1]->after)->toBe(['reason' => 'Feriado'])
        ->and($rows[0]->after['starts_at'])->toBe('2026-12-24T03:00:00+00:00');
});
