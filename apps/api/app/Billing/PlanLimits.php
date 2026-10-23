<?php

namespace App\Billing;

use App\Models\Plan;
use App\Plans\EntitlementResolver;
use App\Plans\TenantEntitlementOverrides;
use Illuminate\Database\Connection;

/**
 * Confere o uso do negócio contra os limites de um plano (já com as exceções do negócio). Só mede o que existe:
 * hoje, pessoas ativas mais convites pendentes (`usuarios.max`); as demais funcionalidades contam 0 até terem medição.
 */
class PlanLimits
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly TenantEntitlementOverrides $overrides,
    ) {}

    /**
     * Conexão de plataforma com o negócio já em contexto (`app.tenant_id`).
     *
     * @return list<array{feature: string, limit: int, usage: int}>
     */
    public function violations(Connection $db, string $tenantId, Plan $plan): array
    {
        $violations = [];

        foreach ($this->entitlements->forPlan($plan, $this->overrides->for($tenantId, 'pgsql_platform')) as $entitlement) {
            if (! $entitlement->enabled || $entitlement->limit === null) {
                continue;
            }

            $usage = $this->usage($db, $entitlement->feature);

            if ($usage > $entitlement->limit) {
                $violations[] = ['feature' => $entitlement->feature, 'limit' => $entitlement->limit, 'usage' => $usage];
            }
        }

        return $violations;
    }

    private function usage(Connection $db, string $feature): int
    {
        return match ($feature) {
            'usuarios.max' => $db->table('memberships')->where('status', 'active')->count()
                + $db->table('invitations')->where('expires_at', '>', now())->count(),
            default => 0,
        };
    }
}
