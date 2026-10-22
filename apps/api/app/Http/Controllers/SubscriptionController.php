<?php

namespace App\Http\Controllers;

use App\Billing\SubscriptionAccess;
use App\Billing\SubscriptionManager;
use App\Http\Problem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/** Status da assinatura do negócio do host. Sempre permitido, inclusive suspenso. */
class SubscriptionController extends Controller
{
    public function __invoke(TenantContext $context): JsonResponse
    {
        $subscription = Subscription::query()->where('tenant_id', (string) $context->id())->first();
        $plan = $subscription === null ? null : Plan::query()->find($subscription->plan_id);

        if ($subscription === null || $plan === null) {
            return Problem::response(404, 'Not Found', 'Este negócio não tem assinatura.');
        }

        return response()->json([
            'status' => $subscription->status,
            'plan' => ['key' => $plan->key, 'name' => $plan->name],
            'cycle' => $subscription->cycle,
            'price_cents' => $subscription->price_cents,
            'currency' => $subscription->currency,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'next_charge_at' => SubscriptionAccess::nextChargeAt($subscription)?->toIso8601String(),
            'suspended_at' => $subscription->suspended_at?->toIso8601String(),
            'can_write' => SubscriptionAccess::canWrite($subscription->status),
            'payment_method' => SubscriptionManager::summary($subscription)['payment_method'],
            'cancel_at_period_end' => SubscriptionManager::summary($subscription)['cancel_at_period_end'],
        ]);
    }
}
