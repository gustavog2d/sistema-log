<?php

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-11 returns bodies that respect the openapi contract', function () {
    [, $token] = ownerOf($this, 'neg');
    $this->loadOpenApi('AGD-002-profissionais-e-expediente');

    $professional = profApi($this, $token, 'POST', 'neg', 'professionals', profPayload())->assertCreated();
    expect($this->schemaErrors($professional->json(), 'Professional'))->toBe([]);

    $show = profApi($this, $token, 'GET', 'neg', 'professionals/'.$professional->json('id'))->assertOk();
    expect($this->schemaErrors($show->json(), 'Professional'))->toBe([]);

    $patched = profApi($this, $token, 'PATCH', 'neg', 'professionals/'.$professional->json('id'), ['status' => 'inactive'])->assertOk();
    expect($this->schemaErrors($patched->json(), 'Professional'))->toBe([]);

    $list = profApi($this, $token, 'GET', 'neg', 'professionals?status=all')->assertOk();
    expect($this->schemaErrors($list->json(), 'ProfessionalPage'))->toBe([]);

    $hours = profApi($this, $token, 'PUT', 'neg', 'professionals/'.$professional->json('id').'/working-hours', ['ranges' => [['weekday' => 1, 'start' => '09:00', 'end' => '12:00']]])->assertOk();
    expect($this->schemaErrors($hours->json(), 'WorkingHours'))->toBe([]);
    $read = profApi($this, $token, 'GET', 'neg', 'professionals/'.$professional->json('id').'/working-hours')->assertOk();
    expect($this->schemaErrors($read->json(), 'WorkingHours'))->toBe([]);

    $off = profApi($this, $token, 'POST', 'neg', 'time-offs', timeOffPayload(['professional_id' => $professional->json('id')]))->assertCreated();
    expect($this->schemaErrors($off->json(), 'TimeOff'))->toBe([]);
    $patchedOff = profApi($this, $token, 'PATCH', 'neg', 'time-offs/'.$off->json('id'), ['reason' => 'Outra'])->assertOk();
    expect($this->schemaErrors($patchedOff->json(), 'TimeOff'))->toBe([]);
    $offs = profApi($this, $token, 'GET', 'neg', 'time-offs')->assertOk();
    expect($this->schemaErrors($offs->json(), 'TimeOffPage'))->toBe([]);
});

it('CA-11 returns the documented problem body', function () {
    [, $token] = ownerOf($this, 'neg');
    $this->loadOpenApi('AGD-002-profissionais-e-expediente');

    $problem = profApi($this, $token, 'POST', 'neg', 'professionals', ['name' => 'A', 'color' => 'x'])->assertStatus(422);

    expect($this->schemaErrors($problem->json(), 'Problem'))->toBe([]);
});
