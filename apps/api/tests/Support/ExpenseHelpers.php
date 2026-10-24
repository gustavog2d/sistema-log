<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function expApi($test, string $token, string $method, string $path, array $data = [], string $slug = 'neg'): TestResponse
{
    return $test->withToken($token)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data);
}

function seedExpenseCategory(object $workspace, array $override = []): object
{
    $row = $override + ['id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'name' => 'Categoria '.Str::random(6), 'created_at' => now(), 'updated_at' => now()];
    DB::connection('pgsql_admin')->table('expense_categories')->insert($row);

    return (object) $row;
}

function seedExpense(object $workspace, object $category, array $override = []): object
{
    $row = $override + [
        'id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'category_id' => $category->id,
        'description' => 'Despesa '.Str::random(4), 'amount_cents' => 10000, 'spent_on' => '2026-11-10',
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::connection('pgsql_admin')->table('expenses')->insert($row);

    return (object) $row;
}

function expenseBody(object $category, array $override = []): array
{
    return $override + ['category_id' => $category->id, 'description' => 'Aluguel de novembro', 'amount_cents' => 250000, 'spent_on' => '2026-11-05'];
}
