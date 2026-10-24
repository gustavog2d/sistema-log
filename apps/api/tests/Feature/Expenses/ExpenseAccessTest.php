<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-07 lets only owner and manager read and write', function () {
    $w = apptTeam($this);
    $category = seedExpenseCategory($w->ws);
    $expense = seedExpense($w->ws, $category);

    foreach (['reception', 'ana', 'bia'] as $who) {
        $token = $w->tokens[$who];
        expApi($this, $token, 'GET', 'expense-categories')->assertForbidden();
        expApi($this, $token, 'POST', 'expense-categories', ['name' => 'Novo1'])->assertForbidden();
        expApi($this, $token, 'POST', 'expense-categories/defaults')->assertForbidden();
        expApi($this, $token, 'GET', 'expenses?from=2026-11-01&to=2026-11-30')->assertForbidden();
        expApi($this, $token, 'POST', 'expenses', expenseBody($category))->assertForbidden();
        expApi($this, $token, 'PATCH', "expenses/{$expense->id}", ['amount_cents' => 1])->assertForbidden();
        expApi($this, $token, 'DELETE', "expenses/{$expense->id}")->assertForbidden();
    }
    foreach (['owner', 'manager'] as $who) {
        expApi($this, $w->tokens[$who], 'GET', 'expenses?from=2026-11-01&to=2026-11-30')->assertOk();
    }
    expApi($this, $w->tokens['manager'], 'POST', 'expenses', expenseBody($category))->assertCreated();
});

it('CA-07 gives the support access read-only', function () {
    $w = apptTeam($this);
    $category = seedExpenseCategory($w->ws);
    $expense = seedExpense($w->ws, $category);
    $token = supportAccessToken($w->ws);

    expApi($this, $token, 'GET', 'expense-categories')->assertOk();
    expApi($this, $token, 'GET', 'expenses?from=2026-11-01&to=2026-11-30')->assertOk();
    foreach ([['POST', 'expense-categories', ['name' => 'Novo1']], ['POST', 'expense-categories/defaults', []], ['PATCH', "expense-categories/{$category->id}", ['name' => 'Novo2']], ['DELETE', "expense-categories/{$category->id}", []], ['POST', 'expenses', expenseBody($category)], ['PATCH', "expenses/{$expense->id}", ['amount_cents' => 1]], ['DELETE', "expenses/{$expense->id}", []]] as [$method, $path, $body]) {
        expApi($this, $token, $method, $path, $body)->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }
});

it('CA-08 keeps reading but blocks writes when the subscription is suspended or canceled', function (string $status, string $code) {
    Carbon::setTestNow('2026-11-02 12:00:00');
    [$ws, $token] = ownerOf($this, 'neg', $status, $status === 'suspended' ? ['suspended_at' => now()->subDay()] : []);
    $category = seedExpenseCategory($ws);
    $expense = seedExpense($ws, $category);

    expApi($this, $token, 'GET', 'expense-categories')->assertOk();
    expApi($this, $token, 'GET', 'expenses?from=2026-11-01&to=2026-11-30')->assertOk();
    foreach ([['POST', 'expense-categories', ['name' => 'Novo1']], ['POST', 'expenses', expenseBody($category)], ['PATCH', "expenses/{$expense->id}", ['amount_cents' => 1]], ['DELETE', "expenses/{$expense->id}", []]] as [$method, $path, $body]) {
        expApi($this, $token, $method, $path, $body)->assertForbidden()->assertJsonPath('code', $code);
    }
})->with([['suspended', 'subscription_suspended'], ['canceled', 'subscription_canceled']]);

it('CA-10 asks for a login and does not let the token of one business reach another', function () {
    [, $tokenA] = ownerOf($this, 'negocioa');
    ownerOf($this, 'negociob');

    $this->getJson('http://negocioa.app.test/api/v1/expenses?from=2026-11-01&to=2026-11-30')->assertUnauthorized();
    expApi($this, $tokenA, 'GET', 'expenses?from=2026-11-01&to=2026-11-30', [], 'negociob')->assertUnauthorized();
});
