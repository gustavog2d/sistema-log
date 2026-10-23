<?php

namespace Tests\Support;

use App\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

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
        // Trava: esta suíte recria e trunca tabelas e jamais pode tocar o banco de desenvolvimento.
        foreach (['pgsql', 'pgsql_admin', 'pgsql_platform', 'pgsql_auth'] as $connection) {
            $database = DB::connection($connection)->getDatabaseName();

            if ($database !== TestCase::TEST_DATABASE) {
                throw new \RuntimeException('Os testes só rodam no banco '.TestCase::TEST_DATABASE." (conexão {$connection} aponta para {$database}).");
            }
        }

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

        DB::connection('pgsql_admin')->statement(
            'TRUNCATE tenants, platform_users, platform_audit_logs, personal_access_tokens, plans, features, plan_features, users, memberships, subscriptions, payment_methods, idempotency_keys, invoices, user_access_tokens, password_reset_tokens, coupons, coupon_redemptions, notification_log, tenant_entitlement_overrides, leads, audit_logs CASCADE',
        );
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
