<?php

use Illuminate\Support\Facades\DB;

/** Tabelas de plataforma ou do framework: as únicas que podem existir sem tenant_id. */
const PLATFORM_TABLES = [
    'migrations', 'tenants', 'jobs', 'job_batches', 'failed_jobs',
    'cache', 'cache_locks', 'users', 'password_reset_tokens', 'sessions',
];

it('has rls forced on every table outside the platform allowlist', function () {
    $tables = collect(DB::connection('pgsql_admin')->select(
        "select c.relname, c.relrowsecurity, c.relforcerowsecurity
           from pg_class c join pg_namespace n on n.oid = c.relnamespace
          where n.nspname = 'public' and c.relkind = 'r'",
    ))->reject(fn ($t) => in_array($t->relname, PLATFORM_TABLES, true));

    expect($tables->isNotEmpty())->toBeTrue();

    foreach ($tables as $table) {
        expect($table->relrowsecurity)->toBeTrue("{$table->relname} sem RLS");
        expect($table->relforcerowsecurity)->toBeTrue("{$table->relname} sem FORCE RLS");
    }
});

it('requires tenant_id and a using/with check policy on those tables', function () {
    $tables = collect(DB::connection('pgsql_admin')->select(
        "select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
          where n.nspname = 'public' and c.relkind = 'r'",
    ))->pluck('relname')->reject(fn ($name) => in_array($name, PLATFORM_TABLES, true));

    foreach ($tables as $name) {
        $column = DB::connection('pgsql_admin')->selectOne(
            "select 1 as ok from information_schema.columns where table_name = ? and column_name = 'tenant_id'",
            [$name],
        );
        $policy = DB::connection('pgsql_admin')->selectOne(
            'select qual, with_check from pg_policies where tablename = ?',
            [$name],
        );

        expect($column)->not->toBeNull("{$name} sem tenant_id");
        expect($policy->qual ?? null)->not->toBeNull("{$name} sem USING");
        expect($policy->with_check ?? null)->not->toBeNull("{$name} sem WITH CHECK");
    }
});
