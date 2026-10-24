<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

function apptIds($response): array
{
    return collect($response->assertOk()->json('data'))->pluck('id')->all();
}

it('CA-08 lists what touches the period, ordered by start, with the summaries', function () {
    $w = apptWorld($this);
    $late = seedAppointment($w, ['starts_at' => '2026-11-05 17:00:00', 'ends_at' => '2026-11-05 18:00:00', 'service_id' => $w->service->id]);
    $early = seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);
    seedAppointment($w, ['starts_at' => '2026-12-20 13:00:00', 'ends_at' => '2026-12-20 14:00:00']);

    $response = apptApi($this, $w->token, 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z');

    expect(apptIds($response))->toBe([$early->id, $late->id])
        ->and($response->json('data.1.service.name'))->toBe('Tatuagem pequena')->and($response->json('data.0.service'))->toBeNull()
        ->and($response->json('data.0.client'))->toBe(['id' => $w->client->id, 'name' => 'Bia Lima'])
        ->and($response->json('data.0.professional'))->toBe(['id' => $w->pro->id, 'name' => 'Ana Tattoo', 'color' => '#2563EB']);
});

it('CA-08 includes an appointment that starts before the period but is still going on, and excludes one that ends exactly at the start', function () {
    $w = apptWorld($this);
    $crossing = seedAppointment($w, ['starts_at' => '2026-11-02 23:00:00', 'ends_at' => '2026-11-03 01:00:00']);
    $ending = seedAppointment($w, ['starts_at' => '2026-11-03 01:00:00', 'ends_at' => '2026-11-03 02:00:00']);

    $ids = apptIds(apptApi($this, $w->token, 'GET', 'neg', 'appointments?from=2026-11-03T00:00:00Z&to=2026-11-03T02:00:00Z'));
    expect($ids)->toContain($crossing->id)->toContain($ending->id);

    $ids = apptIds(apptApi($this, $w->token, 'GET', 'neg', 'appointments?from=2026-11-03T02:00:00Z&to=2026-11-04T00:00:00Z'));
    expect($ids)->not->toContain($ending->id);
});

it('CA-08 filters by professional, client and status', function () {
    $w = apptWorld($this);
    $bia = seedProfessional($w->ws, ['name' => 'Bia']);
    $caio = seedClient($w->ws, ['name' => 'Caio', 'phone' => '+5511977776666']);
    $one = seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);
    $two = seedAppointment($w, ['professional_id' => $bia->id, 'client_id' => $caio->id, 'starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00', 'status' => 'completed']);
    $range = 'from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z';

    expect(apptIds(apptApi($this, $w->token, 'GET', 'neg', "appointments?{$range}&professional_id={$bia->id}")))->toBe([$two->id])
        ->and(apptIds(apptApi($this, $w->token, 'GET', 'neg', "appointments?{$range}&client_id={$w->client->id}")))->toBe([$one->id])
        ->and(apptIds(apptApi($this, $w->token, 'GET', 'neg', "appointments?{$range}&status=completed")))->toBe([$two->id])
        ->and(apptIds(apptApi($this, $w->token, 'GET', 'neg', "appointments?{$range}&status=canceled")))->toBe([]);
});

it('CA-08 refuses a missing, too long or invalid period and invalid filters', function (string $query, string $field) {
    $w = apptWorld($this);

    apptApi($this, $w->token, 'GET', 'neg', 'appointments?'.$query)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['to=2026-11-30T00:00:00Z', 'from'],
    ['from=2026-11-01T00:00:00Z', 'to'],
    ['from=ontem&to=2026-11-30T00:00:00Z', 'from'],
    ['from=2026-11-30T00:00:00Z&to=2026-11-01T00:00:00Z', 'to'],
    ['from=2026-01-01T00:00:00Z&to=2026-11-30T00:00:00Z', 'to'],
    ['from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z&status=x', 'status'],
    ['from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z&professional_id=x', 'professional_id'],
    ['from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z&client_id=x', 'client_id'],
    ['from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z&limit=0', 'limit'],
    ['from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z&limit=501', 'limit'],
]);

it('CA-08 limits the number of appointments returned', function () {
    $w = apptWorld($this);
    foreach ([3, 4, 5] as $day) {
        seedAppointment($w, ['starts_at' => "2026-11-0{$day} 13:00:00", 'ends_at' => "2026-11-0{$day} 14:00:00"]);
    }

    expect(apptApi($this, $w->token, 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z&limit=2')->assertOk()->json('data'))->toHaveCount(2);
});

it('CA-13 never lists the appointments of another business', function () {
    $a = apptWorld($this, 'negocioa');
    $b = apptWorld($this, 'negociob');
    $mine = seedAppointment($a);
    seedAppointment($b);

    expect(apptIds(apptApi($this, $a->token, 'GET', 'negocioa', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z')))->toBe([$mine->id]);
});
