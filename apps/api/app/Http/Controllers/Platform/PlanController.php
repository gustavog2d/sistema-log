<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Plans\Actions\SavePlan;
use App\Plans\EntitlementResolver;
use App\Platform\Auth\AuthenticatedPlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Plan::query()->orderBy('position')->get()->map->toApi()->all()]);
    }

    public function show(Plan $plan): JsonResponse
    {
        return response()->json($plan->toApi());
    }

    public function store(Request $request, SavePlan $savePlan): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules(creating: true) + [
            'key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', 'unique:plans,key'],
        ]);

        return response()->json($savePlan->create(AuthenticatedPlatformUser::from($request), $data)->toApi(), 201);
    }

    public function update(Request $request, Plan $plan, SavePlan $savePlan): JsonResponse
    {
        // A chave identifica o plano em integrações e assinaturas: não muda depois de criada.
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules(creating: false) + ['key' => ['prohibited']]);

        return response()->json($savePlan->update(AuthenticatedPlatformUser::from($request), $plan, $data)->toApi());
    }

    public function entitlements(Plan $plan, EntitlementResolver $resolver): JsonResponse
    {
        return response()->json(['data' => $resolver->forPlan($plan)->map->toArray()->all()]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $creating): array
    {
        return [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:32767'],
            'status' => ['sometimes', Rule::in(Plan::STATUSES)],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'monthly_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'yearly_discount_percent' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ];
    }
}
