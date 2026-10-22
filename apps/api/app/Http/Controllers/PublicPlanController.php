<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Plans\Entitlement;
use App\Plans\EntitlementResolver;
use Illuminate\Http\JsonResponse;

/** Planos ativos para a página de cadastro. Só campos públicos. */
class PublicPlanController extends Controller
{
    public function __invoke(EntitlementResolver $resolver): JsonResponse
    {
        $plans = Plan::query()->where('status', 'active')->orderBy('position')->get();

        return response()->json(['data' => $plans->map(fn (Plan $plan) => [
            'key' => $plan->key,
            'name' => $plan->name,
            'position' => $plan->position,
            'trial_days' => $plan->trial_days,
            'currency' => $plan->currency,
            'monthly_price_cents' => $plan->monthly_price_cents,
            'yearly_price_cents' => $plan->yearlyPriceCents(),
            'features' => $resolver->forPlan($plan)
                ->filter(fn (Entitlement $e) => $e->visible)
                ->map(fn (Entitlement $e) => ['feature' => $e->feature, 'enabled' => $e->enabled, 'limit' => $e->limit])
                ->values()
                ->all(),
        ])->values()->all()]);
    }
}
