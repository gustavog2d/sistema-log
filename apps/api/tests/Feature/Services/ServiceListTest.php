<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

function serviceNames($response): array
{
    return collect($response->assertOk()->json('data'))->pluck('name')->all();
}

it('CA-04 lists active services in alphabetical order without accents, with cursor and limit', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    foreach (['Corte', 'Álcool gel', 'Barba', 'Zebra', 'alisamento'] as $name) {
        seedService($ws, ['name' => $name]);
    }

    $first = servicesApi($this, $token, 'GET', 'neg', 'services?limit=2')->assertOk();
    expect(serviceNames($first))->toBe(['Álcool gel', 'alisamento'])->and($first->json('next_cursor'))->not->toBeNull();

    $second = servicesApi($this, $token, 'GET', 'neg', 'services?limit=2&cursor='.urlencode($first->json('next_cursor')));
    expect(serviceNames($second))->toBe(['Barba', 'Corte']);
});

it('CA-04 hides archived services by default and shows them with the status filter', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedService($ws, ['name' => 'Ativo']);
    seedService($ws, ['name' => 'Arquivado', 'status' => 'archived']);

    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg')))->toBe(['Ativo']);
    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg', 'services?status=archived')))->toBe(['Arquivado']);
    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg', 'services?status=all')))->toBe(['Arquivado', 'Ativo']);
});

it('CA-04 filters by category and searches by name ignoring case and accents', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $hair = seedCategory($ws, 'Cabelo');
    seedService($ws, ['name' => 'Coloração', 'category_id' => $hair->id]);
    seedService($ws, ['name' => 'Corte', 'category_id' => $hair->id]);
    seedService($ws, ['name' => 'Manicure']);

    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg', "services?category_id={$hair->id}")))->toBe(['Coloração', 'Corte']);
    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg', 'services?q=coloracao')))->toBe(['Coloração']);
    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg', 'services?q=CO')))->toBe(['Coloração', 'Corte']);
    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg', 'services?q=zzzz')))->toBe([]);
});

it('CA-04 treats LIKE wildcards in the search as plain text', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedService($ws, ['name' => '100% Cor']);
    seedService($ws, ['name' => 'Outro']);

    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg', 'services?q='.urlencode('%%'))))->toBe([]);
    expect(serviceNames(servicesApi($this, $token, 'GET', 'neg', 'services?q='.urlencode('0% c'))))->toBe(['100% Cor']);
});

it('CA-04 refuses invalid filters', function (string $query, string $field) {
    [, $token] = ownerOf($this, 'neg');

    servicesApi($this, $token, 'GET', 'neg', 'services?'.$query)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([['q=a', 'q'], ['limit=0', 'limit'], ['limit=51', 'limit'], ['status=x', 'status'], ['category_id=x', 'category_id']]);

it('CA-04 lists categories alphabetically with the count of services', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $b = seedCategory($ws, 'Barba');
    $a = seedCategory($ws, 'Álcool');
    seedCategory($ws, 'Vazia');
    seedService($ws, ['category_id' => $b->id]);
    seedService($ws, ['category_id' => $b->id, 'status' => 'archived']);
    seedService($ws, ['category_id' => $a->id]);

    $data = servicesApi($this, $token, 'GET', 'neg', 'service-categories')->assertOk()->json('data');

    expect(collect($data)->pluck('name')->all())->toBe(['Álcool', 'Barba', 'Vazia'])
        ->and(collect($data)->pluck('services_count')->all())->toBe([1, 2, 0]);
});

it('CA-09 never lists the catalog of another business', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    seedService($a, ['name' => 'Da A']);
    seedService($b, ['name' => 'Da B']);
    seedCategory($b, 'Categoria B');

    expect(serviceNames(servicesApi($this, $tokenA, 'GET', 'negocioa')))->toBe(['Da A']);
    expect(servicesApi($this, $tokenA, 'GET', 'negocioa', 'service-categories')->json('data'))->toBe([]);
    expect(DB::connection('pgsql_admin')->table('services')->count())->toBe(2);
});
