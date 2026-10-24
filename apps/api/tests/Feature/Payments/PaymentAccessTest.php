<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-09 lets only owner and manager list, register, correct and void payments', function () {
    $w = apptTeam($this);
    $p = seedPayment($w, $w->anaAppointment, ['amount_cents' => 1000]);

    foreach (['reception', 'ana', 'bia'] as $who) {
        $token = $w->tokens[$who];
        payApi($this, $token, 'GET', "appointments/{$w->anaAppointment->id}/payments")->assertForbidden();
        payApi($this, $token, 'POST', "appointments/{$w->anaAppointment->id}/payments", payBody())->assertForbidden();
        payApi($this, $token, 'PATCH', "payments/{$p->id}", ['method' => 'cash'])->assertForbidden();
        payApi($this, $token, 'POST', "payments/{$p->id}/void")->assertForbidden();
    }
    foreach (['owner', 'manager'] as $who) {
        payApi($this, $w->tokens[$who], 'GET', "appointments/{$w->anaAppointment->id}/payments")->assertOk();
    }
    payApi($this, $w->tokens['manager'], 'POST', "appointments/{$w->anaAppointment->id}/payments", payBody())->assertCreated();
});

it('CA-09 gives the support access read-only', function () {
    $w = apptTeam($this);
    $p = seedPayment($w, $w->anaAppointment, ['amount_cents' => 1000]);
    $token = supportAccessToken($w->ws);

    payApi($this, $token, 'GET', "appointments/{$w->anaAppointment->id}/payments")->assertOk();
    foreach ([['POST', "appointments/{$w->anaAppointment->id}/payments", payBody()], ['PATCH', "payments/{$p->id}", ['method' => 'cash']], ['POST', "payments/{$p->id}/void", []]] as [$method, $path, $body]) {
        payApi($this, $token, $method, $path, $body)->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }
});

it('CA-13 keeps reading but blocks writes when the subscription is suspended or canceled', function (string $status, string $code) {
    Carbon::setTestNow('2026-11-02 12:00:00');
    [$ws, $token] = ownerOf($this, 'neg', $status, $status === 'suspended' ? ['suspended_at' => now()->subDay()] : []);
    $w = (object) ['ws' => $ws, 'client' => seedClient($ws), 'pro' => seedProfessional($ws)];
    $a = payAppointment($w);
    $p = seedPayment($w, $a, ['amount_cents' => 1000]);

    payApi($this, $token, 'GET', "appointments/{$a->id}/payments")->assertOk();
    foreach ([['POST', "appointments/{$a->id}/payments", payBody()], ['PATCH', "payments/{$p->id}", ['method' => 'cash']], ['POST', "payments/{$p->id}/void", []]] as [$method, $path, $body]) {
        payApi($this, $token, $method, $path, $body)->assertForbidden()->assertJsonPath('code', $code);
    }
})->with([['suspended', 'subscription_suspended'], ['canceled', 'subscription_canceled']]);

it('CA-12 asks for a login', function () {
    $w = apptWorld($this);
    $a = payAppointment($w);

    $this->getJson("http://neg.app.test/api/v1/appointments/{$a->id}/payments")->assertUnauthorized();
});
