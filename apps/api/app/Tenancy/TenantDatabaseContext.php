<?php

namespace App\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Informa ao PostgreSQL qual é o tenant da conexão (`app.tenant_id`), que é o que as
 * políticas RLS leem. Sempre limpar ao fim da requisição: a conexão pode ser reaproveitada.
 */
class TenantDatabaseContext
{
    public function apply(string $tenantId): void
    {
        DB::statement("select set_config('app.tenant_id', ?, false)", [$tenantId]);
    }

    public function clear(): void
    {
        DB::statement("select set_config('app.tenant_id', '', false)");
    }
}
