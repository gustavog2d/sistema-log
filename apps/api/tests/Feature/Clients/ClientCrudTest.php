<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-01 creates a client normalizing phone, e-mail and Instagram', function () {
    [, $token] = ownerOf($this, 'neg');

    $response = clientsApi($this, $token, 'POST', 'neg', 'clients', [
        'name' => '  Bia Lima ', 'phone' => '+55 (11) 99999-8888', 'email' => ' Bia@Exemplo.COM ', 'instagram' => '@Bia.Lima', 'notes' => 'Prefere à tarde',
    ])->assertCreated()
        ->assertJsonPath('name', 'Bia Lima')
        ->assertJsonPath('phone', '+5511999998888')
        ->assertJsonPath('email', 'bia@exemplo.com')
        ->assertJsonPath('instagram', 'bia.lima')
        ->assertJsonPath('notes', 'Prefere à tarde');

    expect(DB::connection('pgsql_admin')->table('clients')->where('id', $response->json('id'))->value('phone'))->toBe('+5511999998888');
});

it('CA-01 accepts a client with only name and phone', function () {
    [, $token] = ownerOf($this, 'neg');

    clientsApi($this, $token, 'POST', 'neg', 'clients', clientPayload())->assertCreated()
        ->assertJsonPath('email', null)->assertJsonPath('instagram', null)->assertJsonPath('notes', null);
});

it('CA-01 refuses invalid data with the error on the field', function (array $payload, string $field) {
    [, $token] = ownerOf($this, 'neg');

    clientsApi($this, $token, 'POST', 'neg', 'clients', $payload + clientPayload())->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'short name' => [['name' => 'A'], 'name'],
    'long name' => [['name' => str_repeat('a', 101)], 'name'],
    'name with link' => [['name' => 'Veja https://x.com'], 'name'],
    'name with e-mail' => [['name' => 'a@b.com'], 'name'],
    'no phone' => [['phone' => ''], 'phone'],
    'phone without plus' => [['phone' => '11999998888'], 'phone'],
    'phone too short' => [['phone' => '+55119'], 'phone'],
    'phone too long' => [['phone' => '+5511999998888777'], 'phone'],
    'phone with letters' => [['phone' => '+55119999abcd'], 'phone'],
    'bad e-mail' => [['email' => 'nao-e-email'], 'email'],
    'e-mail with accent' => [['email' => 'bía@exemplo.com'], 'email'],
    'bad instagram' => [['instagram' => 'tem espaço'], 'instagram'],
    'long instagram' => [['instagram' => str_repeat('a', 31)], 'instagram'],
    'long notes' => [['notes' => str_repeat('a', 2001)], 'notes'],
    'notes with NUL' => [['notes' => "texto\x00oculto"], 'notes'],
    'notes with bidi control' => [['notes' => "texto\u{202E}invertido"], 'notes'],
    'name with zero width' => [['name' => "Bia\u{200B}Lima"], 'name'],
]);

it('CA-02 refuses a repeated phone, e-mail and Instagram in the same business', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedClient($ws, ['phone' => '+5511999998888', 'email' => 'bia@exemplo.com', 'instagram' => 'bia']);

    clientsApi($this, $token, 'POST', 'neg', 'clients', clientPayload(['phone' => '+55 11 99999-8888']))->assertStatus(422)->assertJsonValidationErrors('phone');
    clientsApi($this, $token, 'POST', 'neg', 'clients', clientPayload(['phone' => '+5511888887777', 'email' => 'BIA@exemplo.com']))->assertStatus(422)->assertJsonValidationErrors('email');
    clientsApi($this, $token, 'POST', 'neg', 'clients', clientPayload(['phone' => '+5511888887777', 'instagram' => '@Bia']))->assertStatus(422)->assertJsonValidationErrors('instagram');
});

it('CA-02 accepts the same contacts in another business and many clients without e-mail or Instagram', function () {
    [$a] = ownerOf($this, 'negocioa');
    [, $tokenB] = ownerOf($this, 'negociob');
    seedClient($a, ['phone' => '+5511999998888', 'email' => 'bia@exemplo.com', 'instagram' => 'bia']);

    clientsApi($this, $tokenB, 'POST', 'negociob', 'clients', clientPayload(['email' => 'bia@exemplo.com', 'instagram' => 'bia']))->assertCreated();
    clientsApi($this, $tokenB, 'POST', 'negociob', 'clients', clientPayload(['phone' => '+5511777776666']))->assertCreated();
    clientsApi($this, $tokenB, 'POST', 'negociob', 'clients', clientPayload(['phone' => '+5511666665555']))->assertCreated();
});

it('CA-02 does not collide with itself on edit but refuses the phone of another client', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $bia = seedClient($ws, ['phone' => '+5511999998888', 'email' => 'bia@exemplo.com']);
    seedClient($ws, ['phone' => '+5511888887777']);

    clientsApi($this, $token, 'PATCH', 'neg', "clients/{$bia->id}", ['name' => 'Beatriz', 'phone' => '+5511999998888', 'email' => 'bia@exemplo.com'])->assertOk()->assertJsonPath('name', 'Beatriz');
    clientsApi($this, $token, 'PATCH', 'neg', "clients/{$bia->id}", ['phone' => '+5511888887777'])->assertStatus(422)->assertJsonValidationErrors('phone');
});

it('CA-06 edits only the fields sent and clears the optional ones with null', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $client = seedClient($ws, ['name' => 'Bia', 'phone' => '+5511999998888', 'email' => 'bia@exemplo.com', 'notes' => 'nota']);

    clientsApi($this, $token, 'PATCH', 'neg', "clients/{$client->id}", ['notes' => null, 'email' => null])->assertOk()
        ->assertJsonPath('name', 'Bia')->assertJsonPath('phone', '+5511999998888')->assertJsonPath('email', null)->assertJsonPath('notes', null);
});

it('CA-06 shows, edits and deletes only clients of the own business', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $mine = seedClient($a);
    $theirs = seedClient($b);

    clientsApi($this, $tokenA, 'GET', 'negocioa', "clients/{$mine->id}")->assertOk()->assertJsonPath('id', $mine->id);
    clientsApi($this, $tokenA, 'GET', 'negocioa', "clients/{$theirs->id}")->assertNotFound();
    clientsApi($this, $tokenA, 'PATCH', 'negocioa', "clients/{$theirs->id}", ['name' => 'Invasor'])->assertNotFound();
    clientsApi($this, $tokenA, 'DELETE', 'negocioa', "clients/{$theirs->id}")->assertNotFound();
    expect(DB::connection('pgsql_admin')->table('clients')->where('id', $theirs->id)->value('name'))->toBe($theirs->name);
});

it('CA-06 deletes a client and answers 404 for an id that is not a uuid', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $client = seedClient($ws);

    clientsApi($this, $token, 'DELETE', 'neg', "clients/{$client->id}")->assertNoContent();
    expect(DB::connection('pgsql_admin')->table('clients')->count())->toBe(0);
    clientsApi($this, $token, 'GET', 'neg', 'clients/nao-e-uuid')->assertNotFound();
});

it('CA-01 keeps line breaks and tabs in the notes', function () {
    [, $token] = ownerOf($this, 'neg');

    clientsApi($this, $token, 'POST', 'neg', 'clients', clientPayload(['notes' => "linha 1\nlinha 2\tcom tab"]))->assertCreated()->assertJsonPath('notes', "linha 1\nlinha 2\tcom tab");
});
