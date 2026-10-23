<?php

namespace App\Plans;

use Illuminate\Support\Facades\DB;

/** Exceções por assinante (PLT-009), no formato que o `EntitlementResolver` espera. */
class TenantEntitlementOverrides
{
    /** @return array<string, array{enabled: bool, limit?: int|null, visible: bool}> indexadas pela chave da funcionalidade */
    public function for(string $tenantId, ?string $connection = null): array
    {
        $rows = DB::connection($connection)->table('tenant_entitlement_overrides')
            ->join('features', 'features.id', '=', 'tenant_entitlement_overrides.feature_id')
            ->where('tenant_entitlement_overrides.tenant_id', $tenantId)
            ->get(['features.key', 'tenant_entitlement_overrides.enabled', 'tenant_entitlement_overrides.limit', 'tenant_entitlement_overrides.visible']);

        $overrides = [];

        foreach ($rows as $row) {
            $overrides[is_string($row->key) ? $row->key : ''] = [
                'enabled' => (bool) $row->enabled,
                'limit' => is_numeric($row->limit) ? (int) $row->limit : null,
                'visible' => (bool) $row->visible,
            ];
        }

        return $overrides;
    }
}
