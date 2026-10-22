<?php

namespace App\Plans;

use App\Models\Feature;
use App\Models\Plan;

/**
 * Projeção pública dos planos ativos (RN-08): só campos externos e só funcionalidades visíveis.
 * Planos ativos sempre têm preço mensal e desconto (RN-03).
 */
class PublicCatalog
{
    public function __construct(private readonly EntitlementResolver $resolver) {}

    /** @return array<int, array<string, mixed>> */
    public function activePlans(): array
    {
        // O catálogo de funcionalidades é lido uma vez para todos os planos.
        $features = Feature::query()->orderBy('key')->get();

        return Plan::query()->where('status', 'active')->orderBy('position')->get()
            ->map(fn (Plan $plan): array => [
                'key' => $plan->key,
                'name' => $plan->name,
                'position' => $plan->position,
                'trial_days' => $plan->trial_days,
                'currency' => $plan->currency,
                'monthly_price_cents' => $plan->monthly_price_cents,
                'yearly_price_cents' => $plan->yearlyPriceCents(),
                'features' => $this->resolver->forPlan($plan, [], $features)
                    ->filter(fn (Entitlement $e) => $e->visible)
                    ->map(fn (Entitlement $e): array => ['feature' => $e->feature, 'enabled' => $e->enabled, 'limit' => $e->limit])
                    ->values()
                    ->all(),
            ])->values()->all();
    }
}
