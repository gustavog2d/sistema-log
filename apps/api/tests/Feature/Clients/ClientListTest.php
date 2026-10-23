<?php

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-04 lists in alphabetical order with a cursor and a limit', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    foreach (['Carla', 'Ana', 'Dora', 'Bia', 'Eva'] as $name) {
        seedClient($ws, ['name' => $name]);
    }

    $first = clientsApi($this, $token, 'GET', 'neg', 'clients?limit=2')->assertOk();
    expect(collect($first->json('data'))->pluck('name')->all())->toBe(['Ana', 'Bia'])->and($first->json('next_cursor'))->not->toBeNull();

    $second = clientsApi($this, $token, 'GET', 'neg', 'clients?limit=2&cursor='.urlencode($first->json('next_cursor')))->assertOk();
    expect(collect($second->json('data'))->pluck('name')->all())->toBe(['Carla', 'Dora']);

    $third = clientsApi($this, $token, 'GET', 'neg', 'clients?limit=2&cursor='.urlencode($second->json('next_cursor')))->assertOk();
    expect(collect($third->json('data'))->pluck('name')->all())->toBe(['Eva'])->and($third->json('next_cursor'))->toBeNull();
});

it('CA-04 sorts without caring about case and accents', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    foreach (['bruna', 'Álvaro', 'Ana', 'Zeca'] as $name) {
        seedClient($ws, ['name' => $name]);
    }

    expect(collect(clientsApi($this, $token, 'GET', 'neg')->json('data'))->pluck('name')->all())->toBe(['Álvaro', 'Ana', 'bruna', 'Zeca']);
});

it('CA-04 searches by name, phone digits, e-mail and Instagram ignoring case and accents', function (string $query, array $expected) {
    [$ws, $token] = ownerOf($this, 'neg');
    seedClient($ws, ['name' => 'José Antônio', 'phone' => '+5511999990001', 'email' => 'jose@exemplo.com', 'instagram' => 'zeantonio']);
    seedClient($ws, ['name' => 'Maria', 'phone' => '+5521988882222', 'email' => 'maria@loja.com.br', 'instagram' => 'maria.tattoo']);

    $names = collect(clientsApi($this, $token, 'GET', 'neg', 'clients?q='.urlencode($query))->assertOk()->json('data'))->pluck('name')->all();

    expect($names)->toBe($expected);
})->with([
    'name with accent typed without' => ['jose', ['José Antônio']],
    'name uppercase' => ['MARIA', ['Maria']],
    'phone piece' => ['8888', ['Maria']],
    'phone with mask' => ['(21) 98888', ['Maria']],
    'e-mail' => ['loja.com', ['Maria']],
    'instagram' => ['tattoo', ['Maria']],
    'no match' => ['zzzz', []],
]);

it('CA-04 refuses a search that is too short or too long and an invalid limit', function (string $query) {
    [, $token] = ownerOf($this, 'neg');

    clientsApi($this, $token, 'GET', 'neg', 'clients?'.$query)->assertStatus(422);
})->with(['q=a', 'q='.'a-very-long-search-'.'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx', 'limit=0', 'limit=51']);

it('CA-04 never returns the clients of another business', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    seedClient($a, ['name' => 'Da A']);
    seedClient($b, ['name' => 'Da B']);

    expect(collect(clientsApi($this, $tokenA, 'GET', 'negocioa')->json('data'))->pluck('name')->all())->toBe(['Da A']);
    expect(clientsApi($this, $tokenA, 'GET', 'negocioa', 'clients?q=Da+B')->json('data'))->toBe([]);
});

it('CA-04 treats LIKE wildcards in the search as plain text', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedClient($ws, ['name' => '100% Tinta']);
    seedClient($ws, ['name' => 'Outro']);

    expect(collect(clientsApi($this, $token, 'GET', 'neg', 'clients?q='.urlencode('%'))->json('data')))->toHaveCount(0);
    expect(collect(clientsApi($this, $token, 'GET', 'neg', 'clients?q='.urlencode('0% t'))->json('data'))->pluck('name')->all())->toBe(['100% Tinta']);
});
