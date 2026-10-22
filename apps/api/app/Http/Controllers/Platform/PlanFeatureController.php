<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\Plan;
use App\Plans\Actions\SetPlanFeatureRule;
use App\Platform\Auth\AuthenticatedPlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PlanFeatureController extends Controller
{
    public function set(Request $request, Plan $plan, Feature $feature, SetPlanFeatureRule $rule): JsonResponse
    {
        /** @var array{enabled: bool, limit?: int|null, visible: bool} $data */
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'limit' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'visible' => ['required', 'boolean'],
        ]);

        return response()->json($rule->set(AuthenticatedPlatformUser::from($request), $plan, $feature, $data)->toArray());
    }

    public function reset(Request $request, Plan $plan, Feature $feature, SetPlanFeatureRule $rule): Response
    {
        $rule->reset(AuthenticatedPlatformUser::from($request), $plan, $feature);

        return response()->noContent();
    }
}
