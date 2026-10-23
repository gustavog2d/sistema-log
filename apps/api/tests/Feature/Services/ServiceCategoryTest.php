<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-02 creates a category trimming the name and counting no services', function () {
    [, $token] = ownerOf($this, 'neg');

    servicesApi($this, $token, 'POST', 'neg', 'service-categories', ['name' => '  Tatuagens  '])->assertCreated()
        ->assertJsonPath('name', 'Tatuagens')->assertJsonPath('services_count', 0);
});

it('CA-02 refuses invalid names', function (string $name) {
    [, $token] = ownerOf($this, 'neg');

    servicesApi($this, $token, 'POST', 'neg', 'service-categories', ['name' => $name])->assertStatus(422)->assertJsonValidationErrors('name');
})->with(['', 'A', 'ver https://x.com', 'a@b.com']);

it('CA-02 refuses a repeated name with another spelling, and a long one', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedCategory($ws, 'Coloração');

    foreach (['coloracao', 'COLORAÇÃO', ' Coloração '] as $name) {
        servicesApi($this, $token, 'POST', 'neg', 'service-categories', ['name' => $name])->assertStatus(422)->assertJsonValidationErrors('name');
    }
    servicesApi($this, $token, 'POST', 'neg', 'service-categories', ['name' => str_repeat('a', 61)])->assertStatus(422);
});

it('CA-02 accepts the same name in another business and renames without colliding with itself', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [, $tokenB] = ownerOf($this, 'negociob');
    $category = seedCategory($a, 'Cabelo');
    seedCategory($a, 'Unhas');

    servicesApi($this, $tokenB, 'POST', 'negociob', 'service-categories', ['name' => 'Cabelo'])->assertCreated();
    servicesApi($this, $tokenA, 'PATCH', 'negocioa', "service-categories/{$category->id}", ['name' => 'CABELO'])->assertOk()->assertJsonPath('name', 'CABELO');
    servicesApi($this, $tokenA, 'PATCH', 'negocioa', "service-categories/{$category->id}", ['name' => 'unhas'])->assertStatus(422);
});

it('CA-06 refuses to delete a category that has services and says how many', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $category = seedCategory($ws);
    seedService($ws, ['category_id' => $category->id]);
    seedService($ws, ['category_id' => $category->id, 'status' => 'archived']);

    servicesApi($this, $token, 'DELETE', 'neg', "service-categories/{$category->id}")->assertStatus(409)
        ->assertJsonPath('code', 'category_in_use')->assertJsonPath('count', 2);
    expect(DB::connection('pgsql_admin')->table('service_categories')->count())->toBe(1)->and(DB::connection('pgsql_admin')->table('services')->count())->toBe(2);
});

it('CA-06 deletes a category without services', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $category = seedCategory($ws);

    servicesApi($this, $token, 'DELETE', 'neg', "service-categories/{$category->id}")->assertNoContent();
    expect(DB::connection('pgsql_admin')->table('service_categories')->count())->toBe(0);
});

it('CA-06 does not let the database drop a category that still has services', function () {
    [$ws] = ownerOf($this, 'neg');
    $category = seedCategory($ws);
    seedService($ws, ['category_id' => $category->id]);

    expect(fn () => DB::connection('pgsql_admin')->table('service_categories')->where('id', $category->id)->delete())->toThrow(QueryException::class);
});

it('CA-09 edits and deletes only categories of the own business', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    $theirs = seedCategory($b, 'Da B');

    servicesApi($this, $tokenA, 'PATCH', 'negocioa', "service-categories/{$theirs->id}", ['name' => 'Invasor'])->assertNotFound();
    servicesApi($this, $tokenA, 'DELETE', 'negocioa', "service-categories/{$theirs->id}")->assertNotFound();
    servicesApi($this, $tokenA, 'DELETE', 'negocioa', 'service-categories/nao-e-uuid')->assertNotFound();
    expect(DB::connection('pgsql_admin')->table('service_categories')->where('id', $theirs->id)->value('name'))->toBe('Da B');
});

it('CA-08 records creation, edition and deletion of services and categories in the history', function () {
    [, $token] = ownerOf($this, 'neg');

    $category = servicesApi($this, $token, 'POST', 'neg', 'service-categories', ['name' => 'Cabelo'])->assertCreated()->json('id');
    $service = servicesApi($this, $token, 'POST', 'neg', 'services', servicePayload(['name' => 'Corte', 'price_cents' => 5000, 'category_id' => $category]))->assertCreated()->json('id');
    servicesApi($this, $token, 'PATCH', 'neg', "services/{$service}", ['price_cents' => 6000, 'status' => 'archived'])->assertOk();
    servicesApi($this, $token, 'PATCH', 'neg', "services/{$service}", ['description' => null])->assertOk();
    servicesApi($this, $token, 'PATCH', 'neg', "service-categories/{$category}", ['name' => 'Cabelos'])->assertOk();
    servicesApi($this, $token, 'DELETE', 'neg', "services/{$service}")->assertNoContent();
    servicesApi($this, $token, 'DELETE', 'neg', "service-categories/{$category}")->assertNoContent();

    $rows = auditRows();
    $types = collect($rows)->map(fn ($r) => $r->entity_type.':'.$r->action)->all();

    expect($types)->toBe(['service_category:created', 'service:created', 'service:updated', 'service_category:updated', 'service:deleted', 'service_category:deleted'])
        ->and($rows[1]->after)->toMatchArray(['name' => 'Corte', 'price_cents' => 5000, 'status' => 'active', 'category_id' => $category])
        ->and($rows[2]->before)->toEqual(['price_cents' => 5000, 'status' => 'active'])->and($rows[2]->after)->toEqual(['price_cents' => 6000, 'status' => 'archived'])
        ->and($rows[3]->before)->toBe(['name' => 'Cabelo'])->and($rows[3]->after)->toBe(['name' => 'Cabelos']);
});
