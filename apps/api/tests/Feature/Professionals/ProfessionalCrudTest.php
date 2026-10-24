<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

function addMember(object $workspace, string $email, string $status = 'active', string $role = 'professional'): string
{
    $db = DB::connection('pgsql_admin');
    $user = (string) Str::uuid();
    $db->table('users')->insert(['id' => $user, 'name' => 'Pessoa '.$email, 'email' => $email, 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $db->table('memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'user_id' => $user, 'role' => $role, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

    return $user;
}

it('CA-01 creates a professional with the defaults and a color from the palette', function () {
    [, $token] = ownerOf($this, 'neg');

    $response = profApi($this, $token, 'POST', 'neg', 'professionals', profPayload())->assertCreated()
        ->assertJsonPath('name', 'Ana Tattoo')->assertJsonPath('commission_percent', 0)->assertJsonPath('status', 'active')->assertJsonPath('user_id', null);

    expect($response->json('color'))->toMatch('/^#[0-9A-F]{6}$/');
});

it('CA-01 creates with every field and links an active member of the business', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $user = addMember($ws, 'bia@exemplo.com');

    profApi($this, $token, 'POST', 'neg', 'professionals', profPayload(['name' => '  Bia  Souza ', 'color' => '#db2777', 'commission_percent' => 40, 'user_id' => $user]))->assertCreated()
        ->assertJsonPath('name', 'Bia Souza')->assertJsonPath('color', '#DB2777')->assertJsonPath('commission_percent', 40)->assertJsonPath('user_id', $user);
});

it('CA-01 refuses invalid data with the error on the field', function (array $payload, string $field) {
    [, $token] = ownerOf($this, 'neg');

    profApi($this, $token, 'POST', 'neg', 'professionals', $payload + profPayload())->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'short name' => [['name' => 'A'], 'name'],
    'long name' => [['name' => str_repeat('a', 101)], 'name'],
    'name with link' => [['name' => 'Veja https://x.com'], 'name'],
    'bad color' => [['color' => 'azul'], 'color'],
    'short color' => [['color' => '#FFF'], 'color'],
    'commission above 100' => [['commission_percent' => 101], 'commission_percent'],
    'negative commission' => [['commission_percent' => -1], 'commission_percent'],
    'decimal commission' => [['commission_percent' => 10.5], 'commission_percent'],
    'user that is not a uuid' => [['user_id' => 'x'], 'user_id'],
    'unknown user' => [['user_id' => '0194a7c0-0000-7000-8000-000000000000'], 'user_id'],
    'bad status' => [['status' => 'x'], 'status'],
]);

it('CA-01 refuses a person of another business, an inactive member and a person already linked', function () {
    [$a, $token] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $foreign = addMember($b, 'fora@exemplo.com');
    $inactive = addMember($a, 'inativa@exemplo.com', 'inactive');
    $taken = addMember($a, 'ocupada@exemplo.com');
    seedProfessional($a, ['name' => 'Já vinculada', 'user_id' => $taken]);

    foreach ([$foreign, $inactive, $taken] as $user) {
        profApi($this, $token, 'POST', 'negocioa', 'professionals', profPayload(['user_id' => $user]))->assertStatus(422)->assertJsonValidationErrors('user_id');
    }
});

it('CA-02 refuses a repeated name with another spelling but accepts it in another business and on itself', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [, $tokenB] = ownerOf($this, 'negociob');
    $one = seedProfessional($a, ['name' => 'Beatriz']);
    seedProfessional($a, ['name' => 'Carla']);

    foreach (['beatriz', 'BEATRIZ', 'Beátriz', ' Beatriz '] as $name) {
        profApi($this, $tokenA, 'POST', 'negocioa', 'professionals', profPayload(['name' => $name]))->assertStatus(422)->assertJsonValidationErrors('name');
    }
    profApi($this, $tokenB, 'POST', 'negociob', 'professionals', profPayload(['name' => 'Beatriz']))->assertCreated();
    profApi($this, $tokenA, 'PATCH', 'negocioa', "professionals/{$one->id}", ['name' => 'BEATRIZ'])->assertOk();
    profApi($this, $tokenA, 'PATCH', 'negocioa', "professionals/{$one->id}", ['name' => 'carla'])->assertStatus(422);
});

it('CA-04 deactivates, reactivates, filters by status and deletes with the working hours', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $one = seedProfessional($ws, ['name' => 'Ana']);
    seedProfessional($ws, ['name' => 'Bia', 'status' => 'inactive']);
    seedHours($ws, $one, [[1, 540, 720], [2, 540, 720]]);

    $names = fn (string $query) => collect(profApi($this, $token, 'GET', 'neg', 'professionals'.$query)->assertOk()->json('data'))->pluck('name')->all();
    expect($names(''))->toBe(['Ana'])->and($names('?status=inactive'))->toBe(['Bia'])->and($names('?status=all'))->toBe(['Ana', 'Bia']);

    profApi($this, $token, 'PATCH', 'neg', "professionals/{$one->id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('status', 'inactive');
    profApi($this, $token, 'PATCH', 'neg', "professionals/{$one->id}", ['status' => 'active'])->assertOk();
    profApi($this, $token, 'DELETE', 'neg', "professionals/{$one->id}")->assertNoContent();

    expect(DB::connection('pgsql_admin')->table('professionals')->count())->toBe(1)->and(DB::connection('pgsql_admin')->table('working_hours')->count())->toBe(0);
});

it('CA-04 lists in alphabetical order without accents with a cursor and a limit', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    foreach (['Carla', 'Álvaro', 'Bia', 'Zeca'] as $name) {
        seedProfessional($ws, ['name' => $name]);
    }

    $first = profApi($this, $token, 'GET', 'neg', 'professionals?limit=2')->assertOk();
    $second = profApi($this, $token, 'GET', 'neg', 'professionals?limit=2&cursor='.urlencode($first->json('next_cursor')))->assertOk();

    expect(collect($first->json('data'))->pluck('name')->all())->toBe(['Álvaro', 'Bia'])->and(collect($second->json('data'))->pluck('name')->all())->toBe(['Carla', 'Zeca'])->and($second->json('next_cursor'))->toBeNull();
    profApi($this, $token, 'GET', 'neg', 'professionals?limit=0')->assertStatus(422);
    profApi($this, $token, 'GET', 'neg', 'professionals?status=x')->assertStatus(422);
});

it('CA-09 shows, edits and deletes only professionals of the own business', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $mine = seedProfessional($a);
    $theirs = seedProfessional($b);

    profApi($this, $tokenA, 'GET', 'negocioa', "professionals/{$mine->id}")->assertOk();
    profApi($this, $tokenA, 'GET', 'negocioa', "professionals/{$theirs->id}")->assertNotFound();
    profApi($this, $tokenA, 'PATCH', 'negocioa', "professionals/{$theirs->id}", ['name' => 'Invasor'])->assertNotFound();
    profApi($this, $tokenA, 'DELETE', 'negocioa', "professionals/{$theirs->id}")->assertNotFound();
    profApi($this, $tokenA, 'GET', 'negocioa', "professionals/{$theirs->id}/working-hours")->assertNotFound();
    profApi($this, $tokenA, 'GET', 'negocioa', 'professionals/nao-e-uuid')->assertNotFound();
    expect(collect(profApi($this, $tokenA, 'GET', 'negocioa')->json('data'))->pluck('id')->all())->toBe([$mine->id]);
});

it('CA-08 records creation, edition and deletion in the history', function () {
    [, $token] = ownerOf($this, 'neg');

    $id = profApi($this, $token, 'POST', 'neg', 'professionals', profPayload(['commission_percent' => 10, 'color' => '#2563EB']))->assertCreated()->json('id');
    profApi($this, $token, 'PATCH', 'neg', "professionals/{$id}", ['commission_percent' => 25])->assertOk();
    profApi($this, $token, 'DELETE', 'neg', "professionals/{$id}")->assertNoContent();

    $rows = auditRows();
    expect(collect($rows)->map(fn ($r) => $r->entity_type.':'.$r->action)->all())->toBe(['professional:created', 'professional:updated', 'professional:deleted'])
        ->and($rows[1]->before)->toBe(['commission_percent' => 10])->and($rows[1]->after)->toBe(['commission_percent' => 25]);
});

it('CA-03 shows the commission only to owner, manager and the professional themselves', function () {
    [$ownerToken, $ws] = teamOf($this, 'neg', ['recep@exemplo.com' => 'reception', 'pro@exemplo.com' => 'professional']);
    $db = DB::connection('pgsql_admin');
    $proUser = $db->table('users')->where('email', 'pro@exemplo.com')->value('id');
    seedProfessional($ws, ['name' => 'Ana', 'commission_percent' => 40, 'user_id' => $proUser]);
    seedProfessional($ws, ['name' => 'Bia', 'commission_percent' => 25]);

    $commissions = fn (string $token) => collect(profApi($this, $token, 'GET', 'neg')->assertOk()->json('data'))->pluck('commission_percent', 'name')->all();

    expect($commissions($ownerToken))->toBe(['Ana' => 40, 'Bia' => 25])
        ->and($commissions(tokenOf($this, 'neg', 'pro@exemplo.com')))->toBe(['Ana' => 40, 'Bia' => null])
        ->and($commissions(tokenOf($this, 'neg', 'recep@exemplo.com')))->toBe(['Ana' => null, 'Bia' => null]);
});

it('CA-01 lets the same link be sent again after the person left the team, but not a new one', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $user = addMember($ws, 'saiu@exemplo.com');
    $other = addMember($ws, 'outra@exemplo.com', 'inactive');
    $professional = seedProfessional($ws, ['user_id' => $user]);
    DB::connection('pgsql_admin')->table('memberships')->where('user_id', $user)->update(['status' => 'inactive']);

    profApi($this, $token, 'PATCH', 'neg', "professionals/{$professional->id}", ['user_id' => $user, 'commission_percent' => 5])->assertOk();
    profApi($this, $token, 'PATCH', 'neg', "professionals/{$professional->id}", ['user_id' => $other])->assertStatus(422)->assertJsonValidationErrors('user_id');
});

it('CA-04 records the removal of the time offs and of the schedule when a professional is deleted', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $professional = seedProfessional($ws);
    seedHours($ws, $professional, [[1, 540, 720]]);
    $off = seedTimeOff($ws, ['professional_id' => $professional->id]);
    seedTimeOff($ws);

    profApi($this, $token, 'DELETE', 'neg', "professionals/{$professional->id}")->assertNoContent();

    $rows = collect(auditRows());
    expect(DB::connection('pgsql_admin')->table('time_offs')->count())->toBe(1)
        ->and($rows->where('entity_type', 'time_off')->where('action', 'deleted')->pluck('entity_id')->all())->toBe([$off->id])
        ->and($rows->where('entity_type', 'working_hours')->where('action', 'deleted')->first()->before)->toBe(['ranges' => ['1 09:00-12:00']])
        ->and($rows->where('entity_type', 'professional')->where('action', 'deleted')->count())->toBe(1);
});
