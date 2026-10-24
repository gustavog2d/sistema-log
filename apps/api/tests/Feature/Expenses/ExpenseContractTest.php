<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-12 returns bodies that respect the openapi contract', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $this->loadOpenApi('FIN-002-despesas');

    $category = expApi($this, $token, 'POST', 'expense-categories', ['name' => 'Aluguel'])->assertCreated();
    expect($this->schemaErrors($category->json(), 'ExpenseCategory'))->toBe([]);

    foreach (expApi($this, $token, 'GET', 'expense-categories')->assertOk()->json('data') as $item) {
        expect($this->schemaErrors($item, 'ExpenseCategory'))->toBe([]);
    }

    $expense = expApi($this, $token, 'POST', 'expenses', ['category_id' => $category->json('id'), 'description' => 'Aluguel', 'amount_cents' => 1000, 'spent_on' => '2026-11-05'])->assertCreated();
    expect($this->schemaErrors($expense->json(), 'Expense'))->toBe([]);
    expect($this->schemaErrors(expApi($this, $token, 'GET', 'expenses/'.$expense->json('id'))->json(), 'Expense'))->toBe([]);
    expect($this->schemaErrors(expApi($this, $token, 'PATCH', 'expenses/'.$expense->json('id'), ['amount_cents' => 2000])->json(), 'Expense'))->toBe([]);
    expect($this->schemaErrors(expApi($this, $token, 'GET', 'expenses?from=2026-11-01&to=2026-11-30')->json(), 'ExpenseList'))->toBe([]);
    expect($ws->tenant_id)->not->toBeNull();
});

it('CA-12 returns the documented problem bodies', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $this->loadOpenApi('FIN-002-despesas');
    $category = seedExpenseCategory($ws);
    seedExpense($ws, $category);

    $inUse = expApi($this, $token, 'DELETE', "expense-categories/{$category->id}")->assertStatus(409);
    expect($this->schemaErrors($inUse->json(), 'Problem'))->toBe([])->and($inUse->json('count'))->toBe(1);
    $invalid = expApi($this, $token, 'POST', 'expenses', [])->assertStatus(422);
    expect($this->schemaErrors($invalid->json(), 'Problem'))->toBe([]);
});
