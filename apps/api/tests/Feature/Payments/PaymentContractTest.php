<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-15 returns bodies that respect the openapi contract', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $this->loadOpenApi('FIN-001-pagamentos');

    $created = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody())->assertCreated();
    expect($this->schemaErrors($created->json(), 'Payment'))->toBe([]);

    $list = payApi($this, $w->token, 'GET', "appointments/{$a->id}/payments")->assertOk();
    expect($this->schemaErrors($list->json(), 'PaymentList'))->toBe([]);

    $patched = payApi($this, $w->token, 'PATCH', 'payments/'.$created->json('id'), ['method' => 'cash'])->assertOk();
    expect($this->schemaErrors($patched->json(), 'Payment'))->toBe([]);

    $voided = payApi($this, $w->token, 'POST', 'payments/'.$created->json('id').'/void')->assertOk();
    expect($this->schemaErrors($voided->json(), 'Payment'))->toBe([]);

    $this->loadOpenApi('AGD-003-agenda');
    expect($this->schemaErrors(apptApi($this, $w->token, 'GET', 'neg', "appointments/{$a->id}")->json(), 'Appointment'))->toBe([]);
});

it('CA-15 returns the documented problem bodies', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);
    $this->loadOpenApi('FIN-001-pagamentos');

    $over = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody(['amount_cents' => 15001]))->assertStatus(422);
    expect($this->schemaErrors($over->json(), 'Problem'))->toBe([])->and($over->json('remaining_cents'))->toBe(15000);

    $invalid = payApi($this, $w->token, 'POST', "appointments/{$a->id}/payments", payBody(['method' => 'x']))->assertStatus(422);
    expect($this->schemaErrors($invalid->json(), 'Problem'))->toBe([]);
});
