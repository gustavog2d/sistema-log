<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Plans\EntitlementResolver;
use App\Platform\Auth\AuthenticatedPlatformUser;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PlanFeatureController extends Controller
{
    public function set(Request $request, Plan $plan, Feature $feature, EntitlementResolver $resolver, PlatformAudit $audit): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'visible' => ['required', 'boolean'],
        ]);

        if ($feature->kind === 'toggle' && ($data['limit'] ?? null) !== null) {
            throw ValidationException::withMessages(['limit' => 'Funcionalidade de liberar/bloquear não aceita limite.']);
        }

        $rule = PlanFeature::query()->where(['plan_id' => $plan->id, 'feature_id' => $feature->id])->first();
        $before = $rule?->only(['enabled', 'limit', 'visible']);

        PlanFeature::query()->updateOrCreate(
            ['plan_id' => $plan->id, 'feature_id' => $feature->id],
            ['enabled' => $data['enabled'], 'limit' => $data['limit'] ?? null, 'visible' => $data['visible']],
        );

        $audit->record('plan_feature.set', AuthenticatedPlatformUser::from($request), [
            'plan' => $plan->id, 'feature' => $feature->key, 'before' => $before, 'after' => $data,
        ]);

        return response()->json($resolver->forPlan($plan)->firstWhere('feature', $feature->key)?->toArray());
    }

    public function reset(Request $request, Plan $plan, Feature $feature, PlatformAudit $audit): Response
    {
        $rule = PlanFeature::query()->where(['plan_id' => $plan->id, 'feature_id' => $feature->id])->first();

        if ($rule !== null) {
            $before = $rule->only(['enabled', 'limit', 'visible']);
            $rule->delete();
            $audit->record('plan_feature.reset', AuthenticatedPlatformUser::from($request), [
                'plan' => $plan->id, 'feature' => $feature->key, 'before' => $before,
            ]);
        }

        return response()->noContent();
    }
}
