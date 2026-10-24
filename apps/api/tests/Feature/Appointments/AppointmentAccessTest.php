<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-07 gives owner and manager every appointment with the values', function (string $who) {
    $w = apptTeam($this);

    $list = apptApi($this, $w->tokens[$who], 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertOk()->json('data');

    expect(collect($list)->pluck('value_total_cents')->sort()->values()->all())->toBe([9000, 15000]);
})->with(['owner', 'manager']);

it('CA-07 hides the values from the reception but lets it manage every appointment', function () {
    $w = apptTeam($this);
    $token = $w->tokens['reception'];

    $list = apptApi($this, $token, 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertOk()->json('data');
    expect($list)->toHaveCount(2)->and(collect($list)->pluck('value_total_cents')->unique()->all())->toBe([null]);
    apptApi($this, $token, 'GET', 'neg', "appointments/{$w->anaAppointment->id}")->assertOk()->assertJsonPath('value_total_cents', null);

    $created = apptApi($this, $token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-04T10:00:00-03:00', 'value_total_cents' => 99999]))->assertCreated();
    expect($created->json('value_total_cents'))->toBeNull()
        ->and(DB::connection('pgsql_admin')->table('appointments')->where('id', $created->json('id'))->value('value_total_cents'))->toBe(15000);
    apptApi($this, $token, 'PATCH', 'neg', 'appointments/'.$created->json('id'), ['value_total_cents' => 1, 'notes' => 'ok'])->assertOk();
    expect(DB::connection('pgsql_admin')->table('appointments')->where('id', $created->json('id'))->value('value_total_cents'))->toBe(15000);
});

it('CA-07 gives a professional only the own appointments, with the values', function () {
    $w = apptTeam($this);

    $list = apptApi($this, $w->tokens['ana'], 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertOk()->json('data');
    expect(collect($list)->pluck('id')->all())->toBe([$w->anaAppointment->id])->and($list[0]['value_total_cents'])->toBe(15000);

    apptApi($this, $w->tokens['ana'], 'GET', 'neg', "appointments/{$w->anaAppointment->id}")->assertOk();
    apptApi($this, $w->tokens['ana'], 'GET', 'neg', "appointments/{$w->biaAppointment->id}")->assertNotFound();
    apptApi($this, $w->tokens['ana'], 'PATCH', 'neg', "appointments/{$w->biaAppointment->id}", ['notes' => 'x'])->assertNotFound();
    apptApi($this, $w->tokens['ana'], 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z&professional_id='.$w->bia->id)->assertOk()->assertJsonPath('data', []);
});

it('CA-07 lets a professional create and edit only for the own professional profile', function () {
    $w = apptTeam($this);
    $token = $w->tokens['ana'];

    apptApi($this, $token, 'POST', 'neg', 'appointments', apptPayload($w, ['starts_at' => '2026-11-04T10:00:00-03:00']))->assertCreated();
    apptApi($this, $token, 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $w->bia->id, 'starts_at' => '2026-11-04T10:00:00-03:00']))->assertStatus(403);
    apptApi($this, $token, 'PATCH', 'neg', "appointments/{$w->anaAppointment->id}", ['professional_id' => $w->bia->id])->assertStatus(403);
    apptApi($this, $token, 'DELETE', 'neg', "appointments/{$w->anaAppointment->id}")->assertForbidden();
});

it('CA-07 gives a professional without a profile an empty list and no way to create', function () {
    $w = apptTeam($this);
    DB::connection('pgsql_admin')->table('professionals')->where('id', $w->pro->id)->update(['user_id' => null]);

    apptApi($this, $w->tokens['ana'], 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertOk()->assertJsonPath('data', []);
    apptApi($this, $w->tokens['ana'], 'POST', 'neg', 'appointments', apptPayload($w))->assertStatus(403);
});

it('CA-07 stops treating a person who left the team as the owner of a professional profile', function () {
    $w = apptTeam($this);
    $user = DB::connection('pgsql_admin')->table('users')->where('email', 'ana@exemplo.com')->value('id');
    $token = $w->tokens['ana'];
    DB::connection('pgsql_admin')->table('memberships')->where('user_id', $user)->update(['status' => 'inactive']);

    // O vínculo continua gravado, mas a pessoa saiu: o acesso já cai pela autenticação.
    apptApi($this, $token, 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertUnauthorized();
});

it('CA-07 gives the support access read-only', function () {
    $w = apptTeam($this);
    $token = supportAccessToken($w->ws);

    apptApi($this, $token, 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertOk();
    apptApi($this, $token, 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-11-03&duration_minutes=60")->assertOk();
    foreach ([['POST', 'appointments'], ['PATCH', "appointments/{$w->anaAppointment->id}"], ['DELETE', "appointments/{$w->anaAppointment->id}"]] as [$method, $path]) {
        apptApi($this, $token, $method, 'neg', $path, apptPayload($w))->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }
});

it('CA-10 lets only owner and manager delete', function () {
    $w = apptTeam($this);

    foreach (['reception', 'ana'] as $who) {
        apptApi($this, $w->tokens[$who], 'DELETE', 'neg', "appointments/{$w->anaAppointment->id}")->assertForbidden();
    }
    apptApi($this, $w->tokens['manager'], 'DELETE', 'neg', "appointments/{$w->anaAppointment->id}")->assertNoContent();
});

it('CA-14 keeps reading but blocks writes when the subscription is suspended or canceled', function (string $status, string $code) {
    Carbon::setTestNow('2026-11-02 12:00:00');
    [$ws, $token] = ownerOf($this, 'neg', $status, $status === 'suspended' ? ['suspended_at' => now()->subDay()] : []);
    $client = seedClient($ws);
    $pro = seedProfessional($ws);
    $w = (object) ['ws' => $ws, 'client' => $client, 'pro' => $pro];
    $row = seedAppointment($w);

    apptApi($this, $token, 'GET', 'neg', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertOk();
    apptApi($this, $token, 'GET', 'neg', "appointments/{$row->id}")->assertOk();
    foreach ([['POST', 'appointments'], ['PATCH', "appointments/{$row->id}"], ['DELETE', "appointments/{$row->id}"]] as [$method, $path]) {
        apptApi($this, $token, $method, 'neg', $path, ['client_id' => $client->id, 'professional_id' => $pro->id, 'starts_at' => '2026-11-03T10:00:00-03:00', 'ends_at' => '2026-11-03T11:00:00-03:00'])->assertForbidden()->assertJsonPath('code', $code);
    }
})->with([['suspended', 'subscription_suspended'], ['canceled', 'subscription_canceled']]);

it('CA-13 asks for a login and does not let the token of one business reach another', function () {
    $a = apptWorld($this, 'negocioa');
    $b = apptWorld($this, 'negociob');

    $this->getJson('http://negocioa.app.test/api/v1/appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertUnauthorized();
    apptApi($this, $a->token, 'GET', 'negociob', 'appointments?from=2026-11-01T00:00:00Z&to=2026-11-10T00:00:00Z')->assertUnauthorized();
    expect($b->ws->tenant_id)->not->toBe($a->ws->tenant_id);
});

it('CA-07 answers 403 to a professional using another professional id, whether it exists or not', function () {
    $w = apptTeam($this);
    $other = Str::uuid()->toString();

    foreach ([$w->bia->id, $other] as $id) {
        apptApi($this, $w->tokens['ana'], 'POST', 'neg', 'appointments', apptPayload($w, ['professional_id' => $id]))->assertForbidden();
    }
});
