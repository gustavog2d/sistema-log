<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tabelas de plataforma ou do framework: as únicas que podem existir sem tenant_id. */
function platformTables(): array
{
    return ['migrations', 'tenants', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks',
        'platform_users', 'platform_audit_logs', 'personal_access_tokens',
        'plans', 'features', 'plan_features'];
}

/** @return list<string> */
function publicTables(): array
{
    return collect(DB::connection('pgsql_admin')->select(
        "select c.relname from pg_class c join pg_namespace n on n.oid = c.relnamespace
          where n.nspname = 'public' and c.relkind = 'r'",
    ))->pluck('relname')->all();
}

/**
 * Descreve cada tabela de negócio que não cumpre o isolamento: RLS ligado e forçado,
 * coluna tenant_id e política com USING e WITH CHECK.
 *
 * @return list<string>
 */
function isolationProblems(): array
{
    $problems = [];

    foreach (array_diff(publicTables(), platformTables()) as $table) {
        $flags = DB::connection('pgsql_admin')->selectOne(
            'select relrowsecurity, relforcerowsecurity from pg_class where oid = ?::regclass',
            [$table],
        );
        $hasColumn = Schema::connection('pgsql_admin')->hasColumn($table, 'tenant_id');
        $policies = DB::connection('pgsql_admin')->select(
            'select qual, with_check from pg_policies where tablename = ?',
            [$table],
        );
        $hasPolicy = count($policies) === 1
            && str_contains((string) $policies[0]->qual, 'app.tenant_id')
            && str_contains((string) $policies[0]->with_check, 'app.tenant_id');

        if (! $flags->relrowsecurity) {
            $problems[] = "{$table}: RLS desligado";
        }
        if (! $flags->relforcerowsecurity) {
            $problems[] = "{$table}: FORCE RLS desligado";
        }
        if (! $hasColumn) {
            $problems[] = "{$table}: sem tenant_id";
        }
        if (! $hasPolicy) {
            $problems[] = "{$table}: sem exatamente uma política USING e WITH CHECK por app.tenant_id";
        }
    }

    return $problems;
}

it('CA-14 has isolation on every table outside the platform allowlist', function () {
    expect(publicTables())->toContain('probe_items');
    expect(isolationProblems())->toBe([]);
});

it('CA-14 detects a business table created without isolation', function () {
    Schema::connection('pgsql_admin')->create('vazando', function ($table) {
        $table->id();
        $table->string('nome');
    });

    try {
        expect(isolationProblems())->toContain(
            'vazando: RLS desligado',
            'vazando: sem tenant_id',
            'vazando: sem exatamente uma política USING e WITH CHECK por app.tenant_id',
        );
    } finally {
        Schema::connection('pgsql_admin')->dropIfExists('vazando');
    }
});

it('CA-14 keeps the platform allowlist free of stale names', function () {
    expect(array_diff(platformTables(), publicTables()))->toBe([]);
});

it('RN-06 indexes tenant_id on tables created with the helper', function () {
    $indexes = DB::connection('pgsql_admin')->select(
        "select indexdef from pg_indexes where tablename = 'probe_items'",
    );

    expect(collect($indexes)->contains(fn ($i) => str_contains($i->indexdef, '(tenant_id)')))->toBeTrue();
});
