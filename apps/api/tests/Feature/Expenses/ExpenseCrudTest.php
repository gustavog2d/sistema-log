<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-04 registers an expense and shows it with the category', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $category = seedExpenseCategory($ws, ['name' => 'Aluguel']);

    $created = expApi($this, $token, 'POST', 'expenses', expenseBody($category, ['description' => '  Aluguel de novembro  ']))->assertCreated();

    expect($created->json())->toMatchArray(['description' => 'Aluguel de novembro', 'amount_cents' => 250000, 'spent_on' => '2026-11-05'])
        ->and($created->json('category'))->toBe(['id' => $category->id, 'name' => 'Aluguel']);
    expApi($this, $token, 'GET', 'expenses/'.$created->json('id'))->assertOk()->assertJsonPath('amount_cents', 250000);
});

it('CA-04 validates the fields', function (array $override, string $field) {
    [$ws, $token] = ownerOf($this, 'neg');
    $category = seedExpenseCategory($ws);

    expApi($this, $token, 'POST', 'expenses', expenseBody($category, $override))->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    [['description' => 'a'], 'description'],
    [['description' => str_repeat('x', 256)], 'description'],
    [['description' => "Com\x01controle"], 'description'],
    [['amount_cents' => 0], 'amount_cents'],
    [['amount_cents' => -1], 'amount_cents'],
    [['amount_cents' => 10.5], 'amount_cents'],
    [['amount_cents' => 100000001], 'amount_cents'],
    [['spent_on' => '05/11/2026'], 'spent_on'],
    [['spent_on' => '2026-02-30'], 'spent_on'],
    [['spent_on' => '1999-12-31'], 'spent_on'],
    [['spent_on' => '2100-01-01'], 'spent_on'],
    [['category_id' => 'x'], 'category_id'],
]);

it('CA-04 refuses a missing field, an unknown category and a category of another business', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    [$wsB] = ownerOf($this, 'negociob');
    $category = seedExpenseCategory($ws);
    $foreign = seedExpenseCategory($wsB);

    expApi($this, $token, 'POST', 'expenses', [])->assertStatus(422)->assertJsonValidationErrors(['category_id', 'description', 'amount_cents', 'spent_on']);
    expApi($this, $token, 'POST', 'expenses', expenseBody($category, ['category_id' => (string) Str::uuid()]))->assertStatus(422)->assertJsonValidationErrors('category_id');
    expApi($this, $token, 'POST', 'expenses', expenseBody($foreign))->assertStatus(422)->assertJsonValidationErrors('category_id');
});

it('CA-04 edits any field', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $a = seedExpenseCategory($ws, ['name' => 'A1']);
    $b = seedExpenseCategory($ws, ['name' => 'B1']);
    $expense = seedExpense($ws, $a);

    $updated = expApi($this, $token, 'PATCH', "expenses/{$expense->id}", ['category_id' => $b->id, 'description' => 'Nova descrição', 'amount_cents' => 777, 'spent_on' => '2026-12-01'])->assertOk();

    expect($updated->json())->toMatchArray(['description' => 'Nova descrição', 'amount_cents' => 777, 'spent_on' => '2026-12-01'])->and($updated->json('category.id'))->toBe($b->id);
    expApi($this, $token, 'PATCH', "expenses/{$expense->id}", ['amount_cents' => 0])->assertStatus(422);
});

it('CA-05 lists the period inclusive, newest first, with the totals of the whole filter', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $rent = seedExpenseCategory($ws, ['name' => 'Aluguel']);
    $stuff = seedExpenseCategory($ws, ['name' => 'Materiais']);
    $first = seedExpense($ws, $rent, ['spent_on' => '2026-11-01', 'amount_cents' => 200000]);
    $mid = seedExpense($ws, $stuff, ['spent_on' => '2026-11-15', 'amount_cents' => 30000]);
    $last = seedExpense($ws, $stuff, ['spent_on' => '2026-11-30', 'amount_cents' => 5000]);
    seedExpense($ws, $rent, ['spent_on' => '2026-10-31', 'amount_cents' => 1]);
    seedExpense($ws, $rent, ['spent_on' => '2026-12-01', 'amount_cents' => 1]);

    $list = expApi($this, $token, 'GET', 'expenses?from=2026-11-01&to=2026-11-30')->assertOk();

    expect(collect($list->json('data'))->pluck('id')->all())->toBe([$last->id, $mid->id, $first->id])
        ->and($list->json('total_cents'))->toBe(235000)->and($list->json('count'))->toBe(3)
        ->and($list->json('by_category'))->toBe([
            ['category_id' => $rent->id, 'name' => 'Aluguel', 'total_cents' => 200000],
            ['category_id' => $stuff->id, 'name' => 'Materiais', 'total_cents' => 35000],
        ]);
});

it('CA-05 filters by category and keeps the totals when the page is limited', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $rent = seedExpenseCategory($ws, ['name' => 'Aluguel']);
    $stuff = seedExpenseCategory($ws, ['name' => 'Materiais']);
    foreach ([1, 2, 3] as $day) {
        seedExpense($ws, $stuff, ['spent_on' => "2026-11-0{$day}", 'amount_cents' => 100]);
    }
    seedExpense($ws, $rent, ['spent_on' => '2026-11-04', 'amount_cents' => 5000]);

    $filtered = expApi($this, $token, 'GET', "expenses?from=2026-11-01&to=2026-11-30&category_id={$stuff->id}&limit=2")->assertOk();

    expect($filtered->json('data'))->toHaveCount(2)->and($filtered->json('total_cents'))->toBe(300)->and($filtered->json('count'))->toBe(3)
        ->and($filtered->json('by_category'))->toHaveCount(1);
});

it('CA-05 refuses a missing, inverted, too long or invalid period and invalid filters', function (string $query, string $field) {
    [$ws, $token] = ownerOf($this, 'neg');

    expApi($this, $token, 'GET', 'expenses?'.$query)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['to=2026-11-30', 'from'],
    ['from=2026-11-01', 'to'],
    ['from=ontem&to=2026-11-30', 'from'],
    ['from=2026-11-30&to=2026-11-01', 'to'],
    ['from=2025-01-01&to=2026-11-30', 'to'],
    ['from=2026-11-01&to=2026-11-30&category_id=x', 'category_id'],
    ['from=2026-11-01&to=2026-11-30&limit=0', 'limit'],
    ['from=2026-11-01&to=2026-11-30&limit=501', 'limit'],
]);

it('CA-05 accepts a period of exactly one year (366 days) and an empty result', function () {
    [$ws, $token] = ownerOf($this, 'neg');

    $empty = expApi($this, $token, 'GET', 'expenses?from=2026-01-01&to=2026-12-31')->assertOk();
    expect($empty->json())->toBe(['data' => [], 'total_cents' => 0, 'count' => 0, 'by_category' => []]);
});

it('CA-06 deletes an expense', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $expense = seedExpense($ws, seedExpenseCategory($ws));

    expApi($this, $token, 'DELETE', "expenses/{$expense->id}")->assertNoContent();
    expect(DB::connection('pgsql_admin')->table('expenses')->count())->toBe(0);
});

it('CA-09 records creation, edition and deletion with before and after', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $category = seedExpenseCategory($ws);

    $id = expApi($this, $token, 'POST', 'expenses', expenseBody($category))->json('id');
    expApi($this, $token, 'PATCH', "expenses/{$id}", ['amount_cents' => 1000])->assertOk();
    expApi($this, $token, 'DELETE', "expenses/{$id}")->assertNoContent();

    $logs = DB::connection('pgsql_admin')->table('audit_logs')->where('entity_type', 'expense')->where('entity_id', $id)->orderBy('created_at')->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['created', 'updated', 'deleted']);
    $update = $logs[1];
    expect(json_decode((string) $update->before, true)['amount_cents'])->toBe(250000)->and(json_decode((string) $update->after, true)['amount_cents'])->toBe(1000);
});

it('CA-10 never reaches an expense of another business', function () {
    [$wsA, $tokenA] = ownerOf($this, 'negocioa');
    [$wsB] = ownerOf($this, 'negociob');
    $foreignCategory = seedExpenseCategory($wsB);
    $foreign = seedExpense($wsB, $foreignCategory, ['spent_on' => '2026-11-10']);

    expApi($this, $tokenA, 'GET', "expenses/{$foreign->id}", [], 'negocioa')->assertNotFound();
    expApi($this, $tokenA, 'PATCH', "expenses/{$foreign->id}", ['amount_cents' => 1], 'negocioa')->assertNotFound();
    expApi($this, $tokenA, 'DELETE', "expenses/{$foreign->id}", [], 'negocioa')->assertNotFound();
    expect(expApi($this, $tokenA, 'GET', 'expenses?from=2026-11-01&to=2026-11-30', [], 'negocioa')->json('total_cents'))->toBe(0)
        ->and($wsA->tenant_id)->not->toBe($wsB->tenant_id);
});

it('CA-10 refuses at the database an expense that points to a category of another business', function () {
    [$wsA] = ownerOf($this, 'negocioa');
    [$wsB] = ownerOf($this, 'negociob');
    $foreignCategory = seedExpenseCategory($wsB);

    expect(fn () => seedExpense($wsA, $foreignCategory))->toThrow(QueryException::class);
});
