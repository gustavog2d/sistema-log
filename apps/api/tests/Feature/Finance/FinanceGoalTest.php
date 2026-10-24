<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-07 has no goal until one is set, then sets, changes and clears it', function () {
    [, $token] = ownerOf($this, 'neg');

    expApi($this, $token, 'GET', 'finance/goal')->assertOk()->assertExactJson(['monthly_goal_cents' => null]);
    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => 1500000])->assertOk()->assertExactJson(['monthly_goal_cents' => 1500000]);
    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => 2000000])->assertOk();
    expect(expApi($this, $token, 'GET', 'finance/goal')->json('monthly_goal_cents'))->toBe(2000000)
        ->and(DB::connection('pgsql_admin')->table('tenant_settings')->count())->toBe(1);
    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => null])->assertOk()->assertExactJson(['monthly_goal_cents' => null]);
    expect(DB::connection('pgsql_admin')->table('tenant_settings')->count())->toBe(0);
});

it('CA-07 refuses an invalid goal', function (mixed $value) {
    [, $token] = ownerOf($this, 'neg');

    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => $value])->assertStatus(422)->assertJsonValidationErrors('monthly_goal_cents');
})->with([[0], [-5], [1000000001], ['muito'], [10.5], [[1]]]);

it('CA-07 requires the field to be sent', function () {
    [, $token] = ownerOf($this, 'neg');

    expApi($this, $token, 'PUT', 'finance/goal', [])->assertStatus(422)->assertJsonValidationErrors('monthly_goal_cents');
});

it('CA-07 records the goal in the history with before and after', function () {
    [, $token] = ownerOf($this, 'neg');
    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => 100000])->assertOk();
    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => 200000])->assertOk();
    expApi($this, $token, 'PUT', 'finance/goal', ['monthly_goal_cents' => null])->assertOk();

    $logs = DB::connection('pgsql_admin')->table('audit_logs')->where('entity_type', 'setting')->orderBy('created_at')->orderBy('id')->get();
    expect($logs->pluck('action')->all())->toBe(['created', 'updated', 'deleted']);
    expect(json_decode((string) $logs[1]->before, true)['value'])->toBe('100000')->and(json_decode((string) $logs[1]->after, true)['value'])->toBe('200000');
});

it('CA-12 keeps the goal of each business apart', function () {
    [, $tokenA] = ownerOf($this, 'negocioa');
    [, $tokenB] = ownerOf($this, 'negociob');

    expApi($this, $tokenA, 'PUT', 'finance/goal', ['monthly_goal_cents' => 500000], 'negocioa')->assertOk();

    expect(expApi($this, $tokenB, 'GET', 'finance/goal', [], 'negociob')->json('monthly_goal_cents'))->toBeNull();
    expApi($this, $tokenB, 'PUT', 'finance/goal', ['monthly_goal_cents' => 700000], 'negociob')->assertOk();
    expect(expApi($this, $tokenA, 'GET', 'finance/goal', [], 'negocioa')->json('monthly_goal_cents'))->toBe(500000);
});
