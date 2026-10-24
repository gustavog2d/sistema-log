<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-11 refuses to delete a client with appointments and says how to proceed', function () {
    $w = apptWorld($this);
    seedAppointment($w);

    clientsApi($this, $w->token, 'DELETE', 'neg', "clients/{$w->client->id}")->assertStatus(409)->assertJsonPath('code', 'client_in_use');
    expect(DB::connection('pgsql_admin')->table('clients')->count())->toBe(1);
});

it('CA-11 deletes a client that has no appointments, and one whose appointments were removed', function () {
    $w = apptWorld($this);
    $row = seedAppointment($w);
    $other = seedClient($w->ws);

    clientsApi($this, $w->token, 'DELETE', 'neg', "clients/{$other->id}")->assertNoContent();
    DB::connection('pgsql_admin')->table('appointments')->where('id', $row->id)->delete();
    clientsApi($this, $w->token, 'DELETE', 'neg', "clients/{$w->client->id}")->assertNoContent();
});

it('CA-11 refuses to delete a service used in appointments and suggests archiving', function () {
    $w = apptWorld($this);
    seedAppointment($w, ['service_id' => $w->service->id]);

    servicesApi($this, $w->token, 'DELETE', 'neg', "services/{$w->service->id}")->assertStatus(409)->assertJsonPath('code', 'service_in_use');
    servicesApi($this, $w->token, 'PATCH', 'neg', "services/{$w->service->id}", ['status' => 'archived'])->assertOk();
    expect(DB::connection('pgsql_admin')->table('services')->count())->toBe(1);
});

it('CA-11 refuses to delete a professional with appointments and suggests deactivating', function () {
    $w = apptWorld($this);
    seedAppointment($w);

    profApi($this, $w->token, 'DELETE', 'neg', "professionals/{$w->pro->id}")->assertStatus(409)->assertJsonPath('code', 'professional_in_use');
    profApi($this, $w->token, 'PATCH', 'neg', "professionals/{$w->pro->id}", ['status' => 'inactive'])->assertOk();
    expect(DB::connection('pgsql_admin')->table('professionals')->count())->toBe(1)->and(DB::connection('pgsql_admin')->table('working_hours')->count())->toBe(7);
});

it('CA-11 keeps the database guard against deleting what appointments use', function () {
    $w = apptWorld($this);
    seedAppointment($w, ['service_id' => $w->service->id]);

    foreach (['clients' => $w->client->id, 'professionals' => $w->pro->id, 'services' => $w->service->id] as $table => $id) {
        expect(fn () => DB::connection('pgsql_admin')->table($table)->where('id', $id)->delete())->toThrow(QueryException::class);
    }
});

it('CA-11 shows the number of appointments (without the canceled) and the average review of the client', function () {
    $w = apptWorld($this);
    seedAppointment($w, ['status' => 'completed', 'client_review' => 5, 'starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00']);
    seedAppointment($w, ['status' => 'completed', 'client_review' => 4, 'starts_at' => '2026-11-04 13:00:00', 'ends_at' => '2026-11-04 14:00:00']);
    seedAppointment($w, ['status' => 'completed', 'client_review' => 4, 'starts_at' => '2026-11-05 13:00:00', 'ends_at' => '2026-11-05 14:00:00']);
    seedAppointment($w, ['status' => 'scheduled', 'starts_at' => '2026-11-06 13:00:00', 'ends_at' => '2026-11-06 14:00:00']);
    seedAppointment($w, ['status' => 'canceled', 'client_review' => null, 'starts_at' => '2026-11-07 13:00:00', 'ends_at' => '2026-11-07 14:00:00']);
    $none = seedClient($w->ws, ['name' => 'Sem agenda', 'phone' => '+5511966665555']);

    $show = clientsApi($this, $w->token, 'GET', 'neg', "clients/{$w->client->id}")->assertOk();
    expect($show->json('appointments_count'))->toBe(4)->and($show->json('average_review'))->toBe(4.33);

    $list = collect(clientsApi($this, $w->token, 'GET', 'neg')->assertOk()->json('data'))->keyBy('id');
    expect($list[$w->client->id]['appointments_count'])->toBe(4)->and($list[$none->id]['appointments_count'])->toBe(0)->and($list[$none->id]['average_review'])->toBeNull();
});

it('CA-11 does not count the appointments of another client or of another business', function () {
    $a = apptWorld($this, 'negocioa');
    $b = apptWorld($this, 'negociob');
    seedAppointment($b);

    expect(clientsApi($this, $a->token, 'GET', 'negocioa', "clients/{$a->client->id}")->json('appointments_count'))->toBe(0);
});
