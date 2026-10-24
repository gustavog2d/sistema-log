<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

function hoursBody(array $ranges): array
{
    return ['ranges' => array_map(fn (array $r) => ['weekday' => $r[0], 'start' => $r[1], 'end' => $r[2]], $ranges)];
}

it('CA-05 replaces the whole week, with several ranges in a day, and returns them ordered', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);
    seedHours($ws, $professional, [[5, 480, 600]]);

    $response = profApi($this, $token, 'PUT', 'neg', "professionals/{$professional->id}/working-hours", hoursBody([
        [2, '14:00', '18:00'], [1, '09:00', '12:00'], [1, '14:00', '18:30'], [0, '08:00', '24:00'],
    ]))->assertOk();

    expect($response->json('data'))->toBe([
        ['weekday' => 0, 'start' => '08:00', 'end' => '24:00'],
        ['weekday' => 1, 'start' => '09:00', 'end' => '12:00'],
        ['weekday' => 1, 'start' => '14:00', 'end' => '18:30'],
        ['weekday' => 2, 'start' => '14:00', 'end' => '18:00'],
    ]);
    expect(profApi($this, $token, 'GET', 'neg', "professionals/{$professional->id}/working-hours")->json('data'))->toBe($response->json('data'))
        ->and(DB::connection('pgsql_admin')->table('working_hours')->count())->toBe(4);
});

it('CA-05 clears the schedule with an empty list and accepts touching ranges', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);

    profApi($this, $token, 'PUT', 'neg', "professionals/{$professional->id}/working-hours", hoursBody([[1, '09:00', '12:00'], [1, '12:00', '15:00']]))->assertOk();
    expect(DB::connection('pgsql_admin')->table('working_hours')->count())->toBe(2);

    profApi($this, $token, 'PUT', 'neg', "professionals/{$professional->id}/working-hours", ['ranges' => []])->assertOk()->assertJsonPath('data', []);
    expect(DB::connection('pgsql_admin')->table('working_hours')->count())->toBe(0);
});

it('CA-06 refuses invalid ranges and changes nothing', function (array $ranges, string $field) {
    [$ws, $token] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);
    seedHours($ws, $professional, [[1, 540, 720]]);

    profApi($this, $token, 'PUT', 'neg', "professionals/{$professional->id}/working-hours", hoursBody($ranges))->assertStatus(422)->assertJsonValidationErrors($field);

    expect(DB::connection('pgsql_admin')->table('working_hours')->count())->toBe(1);
})->with([
    'overlap in a day' => [[[1, '09:00', '12:00'], [1, '11:00', '14:00']], 'ranges'],
    'end before start' => [[[1, '12:00', '09:00']], 'ranges.0.end'],
    'equal start and end' => [[[1, '09:00', '09:00']], 'ranges.0.end'],
    'not multiple of 5' => [[[1, '09:03', '12:00']], 'ranges.0.start'],
    'weekday 7' => [[[7, '09:00', '12:00']], 'ranges.0.weekday'],
    'negative weekday' => [[[-1, '09:00', '12:00']], 'ranges.0.weekday'],
    'malformed time' => [[[1, '9h', '12:00']], 'ranges.0.start'],
    'hour 25' => [[[1, '09:00', '25:00']], 'ranges.0.end'],
    '24:00 as start' => [[[1, '24:00', '24:00']], 'ranges.0.start'],
    '24:05 as end' => [[[1, '09:00', '24:05']], 'ranges.0.end'],
]);

it('CA-06 refuses a body without the list, a list that is too long and non-list values', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);
    $url = "professionals/{$professional->id}/working-hours";

    profApi($this, $token, 'PUT', 'neg', $url, [])->assertStatus(422)->assertJsonValidationErrors('ranges');
    profApi($this, $token, 'PUT', 'neg', $url, ['ranges' => 'x'])->assertStatus(422)->assertJsonValidationErrors('ranges');
    profApi($this, $token, 'PUT', 'neg', $url, hoursBody(array_fill(0, 71, [1, '09:00', '10:00'])))->assertStatus(422)->assertJsonValidationErrors('ranges');
});

it('CA-06 keeps the database guard against overlapping ranges written directly', function () {
    [$ws] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);
    seedHours($ws, $professional, [[1, 540, 720]]);

    expect(fn () => seedHours($ws, $professional, [[1, 600, 800]]))->toThrow(QueryException::class);
    seedHours($ws, $professional, [[2, 600, 800]]);
    expect(DB::connection('pgsql_admin')->table('working_hours')->count())->toBe(2);
});

it('CA-08 records the change of the schedule in one history entry with the previous and the new set', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);

    profApi($this, $token, 'PUT', 'neg', "professionals/{$professional->id}/working-hours", hoursBody([[1, '09:00', '12:00'], [2, '09:00', '12:00']]))->assertOk();
    profApi($this, $token, 'PUT', 'neg', "professionals/{$professional->id}/working-hours", hoursBody([[3, '10:00', '16:00']]))->assertOk();
    profApi($this, $token, 'PUT', 'neg', "professionals/{$professional->id}/working-hours", hoursBody([[3, '10:00', '16:00']]))->assertOk();

    $rows = array_values(array_filter(auditRows(), fn ($r) => $r->entity_type === 'working_hours'));
    expect($rows)->toHaveCount(2)
        ->and($rows[0]->entity_id)->toBe($professional->id)->and($rows[0]->action)->toBe('updated')
        ->and($rows[0]->before)->toBe(['ranges' => []])
        ->and($rows[0]->after['ranges'])->toBe(['1 09:00-12:00', '2 09:00-12:00'])
        ->and($rows[1]->before['ranges'])->toBe(['1 09:00-12:00', '2 09:00-12:00'])->and($rows[1]->after['ranges'])->toBe(['3 10:00-16:00']);
});

it('CA-09 does not let one business set the schedule of the professional of another', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $theirs = seedProfessional($b);

    profApi($this, $tokenA, 'PUT', 'negocioa', "professionals/{$theirs->id}/working-hours", hoursBody([[1, '09:00', '12:00']]))->assertNotFound();
    expect(DB::connection('pgsql_admin')->table('working_hours')->count())->toBe(0);
    expect(fn () => seedHours($a, $theirs, [[1, 540, 600]]))->toThrow(QueryException::class);
});
