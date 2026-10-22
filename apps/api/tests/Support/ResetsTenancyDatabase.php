<?php

namespace Tests\Support;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prepara um banco PostgreSQL real para os testes de isolamento.
 *
 * As migrations rodam com o papel dono (pgsql_admin); os testes executam com o papel de
 * runtime (pgsql), que não é superusuário e por isso respeita o RLS.
 */
trait ResetsTenancyDatabase
{
    private static bool $databaseMigrated = false;

    protected function setUpResetsTenancyDatabase(): void
    {
        if (! self::$databaseMigrated) {
            Artisan::call('migrate:fresh', ['--database' => 'pgsql_admin', '--force' => true]);
            self::$databaseMigrated = true;
        }

        Schema::connection('pgsql_admin')->dropIfExists('probe_items');
        Schema::connection('pgsql_admin')->create('probe_items', function (Blueprint $table) {
            $table->id();
            $table->tenantId();
            $table->string('name');
        });
        Schema::connection('pgsql_admin')->enableTenantRls('probe_items');

        DB::connection('pgsql_admin')->statement('TRUNCATE tenants CASCADE');
        app(TenantContext::class)->clear();
        app(TenantDatabaseContext::class)->clear();
    }

    protected function tearDownResetsTenancyDatabase(): void
    {
        Schema::connection('pgsql_admin')->dropIfExists('probe_items');
    }

    protected function makeTenant(string $slug): Tenant
    {
        // O papel de runtime só lê `tenants`; o cenário é montado com o papel dono.
        $tenant = new Tenant(['slug' => $slug, 'name' => ucfirst($slug), 'status' => 'active']);
        $tenant->setConnection('pgsql_admin');
        $tenant->save();

        return $tenant;
    }

    /** Insere uma sonda ignorando o RLS (papel dono), para montar o cenário. */
    protected function seedProbe(Tenant $tenant, string $name): void
    {
        DB::connection('pgsql_admin')->table('probe_items')->insert([
            'tenant_id' => $tenant->id,
            'name' => $name,
        ]);
    }
}
