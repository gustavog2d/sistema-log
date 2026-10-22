<?php

namespace App\Providers;

use App\Tenancy\SubdomainTenantResolver;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->bind(TenantResolver::class, SubdomainTenantResolver::class);
    }

    public function boot(): void
    {
        // $table->tenantId(): coluna, FK e índice do tenant em tabelas de negócio.
        Blueprint::macro('tenantId', function () {
            /** @var Blueprint $this */
            $this->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
        });

        // Schema::enableTenantRls('tabela'): liga e força o RLS com política USING e WITH CHECK.
        Builder::macro('enableTenantRls', function (string $table) {
            /** @var Builder $this */
            $connection = $this->getConnection();
            $name = $connection->getSchemaGrammar()->wrapTable($table);
            $condition = "tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid";

            $connection->statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
            $connection->statement("ALTER TABLE {$name} FORCE ROW LEVEL SECURITY");
            $connection->statement(
                "CREATE POLICY tenant_isolation ON {$name} USING ({$condition}) WITH CHECK ({$condition})",
            );
        });
    }
}
