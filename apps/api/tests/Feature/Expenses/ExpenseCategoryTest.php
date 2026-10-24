<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-01 creates, lists by name and renames categories', function () {
    [$ws, $token] = ownerOf($this, 'neg');

    $created = expApi($this, $token, 'POST', 'expense-categories', ['name' => '  Aluguel  '])->assertCreated();
    expApi($this, $token, 'POST', 'expense-categories', ['name' => 'Materiais'])->assertCreated();

    expect($created->json())->toMatchArray(['name' => 'Aluguel', 'expenses_count' => 0]);
    expect(collect(expApi($this, $token, 'GET', 'expense-categories')->assertOk()->json('data'))->pluck('name')->all())->toBe(['Aluguel', 'Materiais']);
    expApi($this, $token, 'PATCH', 'expense-categories/'.$created->json('id'), ['name' => 'Aluguel do estúdio'])->assertOk()->assertJsonPath('name', 'Aluguel do estúdio');
    expApi($this, $token, 'PATCH', 'expense-categories/'.$created->json('id'), ['name' => 'Aluguel do estúdio'])->assertOk();
    expect($ws->tenant_id)->not->toBeNull();
});

it('CA-01 refuses invalid and repeated names, ignoring case and accents', function (string $name) {
    [$ws, $token] = ownerOf($this, 'neg');
    seedExpenseCategory($ws, ['name' => 'Água e luz']);

    expApi($this, $token, 'POST', 'expense-categories', ['name' => $name])->assertStatus(422)->assertJsonValidationErrors('name');
})->with(['agua e LUZ', 'ÁGUA E LUZ', 'a', str_repeat('x', 61), "Com\x01controle"]);

it('CA-01 refuses a missing name and renaming into another category name', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $other = seedExpenseCategory($ws, ['name' => 'Marketing']);
    seedExpenseCategory($ws, ['name' => 'Impostos']);

    expApi($this, $token, 'POST', 'expense-categories', [])->assertStatus(422)->assertJsonValidationErrors('name');
    expApi($this, $token, 'PATCH', "expense-categories/{$other->id}", ['name' => 'impostos'])->assertStatus(422)->assertJsonValidationErrors('name');
});

it('CA-02 refuses to delete a category with expenses and says how many', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $category = seedExpenseCategory($ws);
    seedExpense($ws, $category);
    seedExpense($ws, $category);

    expApi($this, $token, 'DELETE', "expense-categories/{$category->id}")->assertStatus(409)->assertJsonPath('code', 'category_in_use')->assertJsonPath('count', 2);
    expect(expApi($this, $token, 'GET', 'expense-categories')->json('data.0.expenses_count'))->toBe(2);
});

it('CA-02 deletes a category without expenses and keeps the database guard', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $empty = seedExpenseCategory($ws);
    $used = seedExpenseCategory($ws);
    seedExpense($ws, $used);

    expApi($this, $token, 'DELETE', "expense-categories/{$empty->id}")->assertNoContent();
    expect(fn () => DB::connection('pgsql_admin')->table('expense_categories')->where('id', $used->id)->delete())->toThrow(QueryException::class);
});

it('CA-03 creates the suggested categories that are missing and does not repeat them', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedExpenseCategory($ws, ['name' => 'aluguel']);

    $first = expApi($this, $token, 'POST', 'expense-categories/defaults')->assertOk();
    expect($first->json())->toBe(['created' => 6, 'skipped' => 1]);
    $again = expApi($this, $token, 'POST', 'expense-categories/defaults')->assertOk();
    expect($again->json())->toBe(['created' => 0, 'skipped' => 7])->and(DB::connection('pgsql_admin')->table('expense_categories')->count())->toBe(7);
});

it('CA-09 records category creation, renaming and deletion in the history', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $id = expApi($this, $token, 'POST', 'expense-categories', ['name' => 'Marketing'])->json('id');
    expApi($this, $token, 'PATCH', "expense-categories/{$id}", ['name' => 'Publicidade'])->assertOk();
    expApi($this, $token, 'DELETE', "expense-categories/{$id}")->assertNoContent();

    $actions = DB::connection('pgsql_admin')->table('audit_logs')->where('entity_type', 'expense_category')->where('entity_id', $id)->orderBy('created_at')->orderBy('id')->pluck('action')->all();
    expect($actions)->toBe(['created', 'updated', 'deleted']);
});

it('CA-10 keeps categories of each business apart and allows the same name in two businesses', function () {
    [$wsA, $tokenA] = ownerOf($this, 'negocioa');
    [$wsB, $tokenB] = ownerOf($this, 'negociob');
    $b = seedExpenseCategory($wsB, ['name' => 'Aluguel']);

    expApi($this, $tokenA, 'POST', 'expense-categories', ['name' => 'Aluguel'], 'negocioa')->assertCreated();
    expect(collect(expApi($this, $tokenA, 'GET', 'expense-categories', [], 'negocioa')->json('data'))->pluck('id')->all())->not->toContain($b->id);
    expApi($this, $tokenA, 'PATCH', "expense-categories/{$b->id}", ['name' => 'X1'], 'negocioa')->assertNotFound();
    expApi($this, $tokenA, 'DELETE', "expense-categories/{$b->id}", [], 'negocioa')->assertNotFound();
    expect($wsA->tenant_id)->not->toBe($wsB->tenant_id)->and($tokenB)->not->toBeEmpty();
});

it('CA-01 accepts category names and descriptions with characters that are not control characters', function () {
    [$ws, $token] = ownerOf($this, 'neg');

    $category = expApi($this, $token, 'POST', 'expense-categories', ['name' => 'Taxas @ banco (www)'])->assertCreated();
    expApi($this, $token, 'POST', 'expenses', expenseBody((object) ['id' => $category->json('id')], ['description' => 'Pagamento em https://exemplo.com.br']))->assertCreated();
    expApi($this, $token, 'POST', 'expense-categories', ['name' => "Com\x01controle"])->assertStatus(422)->assertJsonValidationErrors('name');
    expect($ws->tenant_id)->not->toBeNull();
});
