<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-16 returns bodies that respect the openapi contract', function () {
    $w = apptWorld($this);
    $this->loadOpenApi('AGD-003-agenda');

    $created = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['notes' => 'nota', 'description' => 'desc', 'session_number' => 1, 'sessions_total' => 3]))->assertCreated();
    expect($this->schemaErrors($created->json(), 'Appointment'))->toBe([]);

    $show = apptApi($this, $w->token, 'GET', 'neg', 'appointments/'.$created->json('id'))->assertOk();
    expect($this->schemaErrors($show->json(), 'Appointment'))->toBe([]);

    $done = apptApi($this, $w->token, 'PATCH', 'neg', 'appointments/'.$created->json('id'), ['status' => 'completed', 'client_review' => 5])->assertOk();
    expect($this->schemaErrors($done->json(), 'Appointment'))->toBe([]);

    $list = apptApi($this, $w->token, 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-30T00:00:00Z')->assertOk();
    expect($list->json('data'))->not->toBe([]);
    foreach ($list->json('data') as $item) {
        expect($this->schemaErrors($item, 'Appointment'))->toBe([]);
    }

    $availability = apptApi($this, $w->token, 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-11-04&duration_minutes=60")->assertOk();
    expect($availability->json('data'))->not->toBe([]);
    foreach ($availability->json('data') as $slot) {
        expect($slot)->toHaveKeys(['starts_at', 'ends_at']);
    }
});

it('CA-16 returns the documented problem bodies', function () {
    $w = apptWorld($this);
    $this->loadOpenApi('AGD-003-agenda');
    seedAppointment($w);

    $conflict = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w))->assertStatus(409);
    expect($this->schemaErrors($conflict->json(), 'Problem'))->toBe([])->and($conflict->json('conflict'))->toHaveKeys(['id', 'starts_at', 'ends_at']);

    $outside = apptApi($this, $w->token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-03T21:00:00-03:00']))->assertStatus(422);
    expect($this->schemaErrors($outside->json(), 'Problem'))->toBe([])->and($outside->json('reasons'))->toBe(['outside_working_hours']);

    $invalid = apptApi($this, $w->token, 'POST', 'neg', 'appointments', ['client_id' => 'x'])->assertStatus(422);
    expect($this->schemaErrors($invalid->json(), 'Problem'))->toBe([]);
});
