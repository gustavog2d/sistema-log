<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-01 creates a service with the defaults', function () {
    [, $token] = ownerOf($this, 'neg');

    servicesApi($this, $token, 'POST', 'neg', 'services', servicePayload())->assertCreated()
        ->assertJsonPath('name', 'Tatuagem pequena')->assertJsonPath('duration_minutes', 90)->assertJsonPath('price_cents', null)
        ->assertJsonPath('buffer_before_minutes', 0)->assertJsonPath('buffer_after_minutes', 0)->assertJsonPath('requires_deposit', false)
        ->assertJsonPath('status', 'active')->assertJsonPath('category_id', null)->assertJsonPath('description', null);
});

it('CA-01 creates a service with every field and a category of the business', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $category = seedCategory($ws);

    servicesApi($this, $token, 'POST', 'neg', 'services', servicePayload([
        'name' => '  Fechamento de braço ', 'description' => 'Várias sessões', 'category_id' => $category->id, 'duration_minutes' => 300,
        'price_cents' => 150000, 'buffer_before_minutes' => 15, 'buffer_after_minutes' => 30, 'requires_deposit' => true,
    ]))->assertCreated()->assertJsonPath('name', 'Fechamento de braço')->assertJsonPath('price_cents', 150000)
        ->assertJsonPath('category_id', $category->id)->assertJsonPath('requires_deposit', true)->assertJsonPath('buffer_after_minutes', 30);
});

it('CA-01 accepts a price of zero and a price left empty as "on request"', function () {
    [, $token] = ownerOf($this, 'neg');

    servicesApi($this, $token, 'POST', 'neg', 'services', servicePayload(['name' => 'Cortesia', 'price_cents' => 0]))->assertCreated()->assertJsonPath('price_cents', 0);
    servicesApi($this, $token, 'POST', 'neg', 'services', servicePayload(['name' => 'Sob orçamento', 'price_cents' => null]))->assertCreated()->assertJsonPath('price_cents', null);
});

it('CA-01 refuses invalid data with the error on the field', function (array $payload, string $field) {
    [, $token] = ownerOf($this, 'neg');

    servicesApi($this, $token, 'POST', 'neg', 'services', $payload + servicePayload())->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'no name' => [['name' => ''], 'name'],
    'short name' => [['name' => 'A'], 'name'],
    'long name' => [['name' => str_repeat('a', 101)], 'name'],
    'name with link' => [['name' => 'Veja https://x.com'], 'name'],
    'long description' => [['description' => str_repeat('a', 501)], 'description'],
    'no duration' => [['duration_minutes' => null], 'duration_minutes'],
    'duration too short' => [['duration_minutes' => 0], 'duration_minutes'],
    'duration not multiple of 5' => [['duration_minutes' => 92], 'duration_minutes'],
    'duration too long' => [['duration_minutes' => 725], 'duration_minutes'],
    'duration as text' => [['duration_minutes' => 'noventa'], 'duration_minutes'],
    'negative price' => [['price_cents' => -1], 'price_cents'],
    'price too high' => [['price_cents' => 100000001], 'price_cents'],
    'price with decimals' => [['price_cents' => 10.5], 'price_cents'],
    'buffer before too long' => [['buffer_before_minutes' => 245], 'buffer_before_minutes'],
    'buffer after not multiple of 5' => [['buffer_after_minutes' => 7], 'buffer_after_minutes'],
    'negative buffer' => [['buffer_before_minutes' => -5], 'buffer_before_minutes'],
    'category that is not a uuid' => [['category_id' => 'x'], 'category_id'],
    'unknown category' => [['category_id' => '0194a7c0-0000-7000-8000-000000000000'], 'category_id'],
    'bad status' => [['status' => 'apagado'], 'status'],
    'deposit as text' => [['requires_deposit' => 'talvez'], 'requires_deposit'],
]);

it('CA-01 refuses a category of another business', function () {
    [, $token] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $foreign = seedCategory($b);

    servicesApi($this, $token, 'POST', 'negocioa', 'services', servicePayload(['category_id' => $foreign->id]))->assertStatus(422)->assertJsonValidationErrors('category_id');
});

it('CA-02 refuses a repeated name with another spelling in the same business, but not in another', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [, $tokenB] = ownerOf($this, 'negociob');
    seedService($a, ['name' => 'Manicure Simples']);

    foreach (['manicure simples', 'MANICURE SIMPLES', 'Manicure Símples', '  Manicure  Simples '] as $name) {
        servicesApi($this, $tokenA, 'POST', 'negocioa', 'services', servicePayload(['name' => $name]))->assertStatus(422)->assertJsonValidationErrors('name');
    }
    servicesApi($this, $tokenB, 'POST', 'negociob', 'services', servicePayload(['name' => 'Manicure Simples']))->assertCreated();
});

it('CA-02 does not collide with itself on edit but refuses the name of another service', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $one = seedService($ws, ['name' => 'Corte']);
    seedService($ws, ['name' => 'Barba']);

    servicesApi($this, $token, 'PATCH', 'neg', "services/{$one->id}", ['name' => 'CORTE', 'duration_minutes' => 45])->assertOk()->assertJsonPath('name', 'CORTE');
    servicesApi($this, $token, 'PATCH', 'neg', "services/{$one->id}", ['name' => 'barba'])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('CA-09 shows, edits and deletes only services of the own business', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $mine = seedService($a);
    $theirs = seedService($b);

    servicesApi($this, $tokenA, 'GET', 'negocioa', "services/{$mine->id}")->assertOk();
    servicesApi($this, $tokenA, 'GET', 'negocioa', "services/{$theirs->id}")->assertNotFound();
    servicesApi($this, $tokenA, 'PATCH', 'negocioa', "services/{$theirs->id}", ['name' => 'Invasor'])->assertNotFound();
    servicesApi($this, $tokenA, 'DELETE', 'negocioa', "services/{$theirs->id}")->assertNotFound();
    servicesApi($this, $tokenA, 'GET', 'negocioa', 'services/nao-e-uuid')->assertNotFound();
    expect(DB::connection('pgsql_admin')->table('services')->where('id', $theirs->id)->value('name'))->toBe($theirs->name);
});

it('CA-05 edits some fields, moves the category, clears optional ones and deletes', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $category = seedCategory($ws);
    $service = seedService($ws, ['name' => 'Corte', 'price_cents' => 5000, 'description' => 'x']);

    servicesApi($this, $token, 'PATCH', 'neg', "services/{$service->id}", ['category_id' => $category->id, 'price_cents' => null, 'description' => null, 'requires_deposit' => true])->assertOk()
        ->assertJsonPath('category_id', $category->id)->assertJsonPath('price_cents', null)->assertJsonPath('description', null)->assertJsonPath('requires_deposit', true)->assertJsonPath('name', 'Corte');
    servicesApi($this, $token, 'PATCH', 'neg', "services/{$service->id}", ['category_id' => null])->assertOk()->assertJsonPath('category_id', null);
    servicesApi($this, $token, 'DELETE', 'neg', "services/{$service->id}")->assertNoContent();
    expect(DB::connection('pgsql_admin')->table('services')->count())->toBe(0);
});

it('CA-01 keeps the database check on durations and status even for rows written directly', function () {
    [$ws] = ownerOf($this, 'neg');

    expect(fn () => seedService($ws, ['duration_minutes' => 3]))->toThrow(QueryException::class);
    expect(fn () => seedService($ws, ['status' => 'x']))->toThrow(QueryException::class);
    expect(fn () => seedService($ws, ['category_id' => (string) Str::uuid()]))->toThrow(QueryException::class);
});
