<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Plans\EntitlementResolver;
use App\Platform\Auth\AuthenticatedPlatformUser;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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

    public function store(Request $request, PlatformAudit $audit): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules(null) + ['key' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', 'unique:plans,key']]);
        $this->assertCanBeActive($data + ['status' => 'draft']);

        $plan = Plan::create($data);
        $audit->record('plan.created', AuthenticatedPlatformUser::from($request), ['plan' => $plan->id, 'after' => $plan->toApi()]);

        return response()->json($plan->refresh()->toApi(), 201);
    }

    public function update(Request $request, Plan $plan, PlatformAudit $audit): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate($this->rules($plan) + ['key' => ['sometimes', 'string', 'max:40', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', Rule::unique('plans', 'key')->ignore($plan->id)]]);
        $this->assertCanBeActive($data + $plan->only(['status', 'monthly_price_cents', 'yearly_discount_percent']));

        $before = $plan->only(array_keys($data));
        $plan->fill($data)->save();

        $audit->record('plan.updated', AuthenticatedPlatformUser::from($request), [
            'plan' => $plan->id,
            'before' => $before,
            'after' => $plan->only(array_keys($data)),
        ]);

        return response()->json($plan->toApi());
    }

    public function entitlements(Plan $plan, EntitlementResolver $resolver): JsonResponse
    {
        return response()->json(['data' => $resolver->forPlan($plan)->map->toArray()->all()]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(?Plan $plan): array
    {
        $required = $plan === null ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:100'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'status' => ['sometimes', Rule::in(Plan::STATUSES)],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'monthly_price_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'yearly_discount_percent' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
        ];
    }

    /** @param  array<string, mixed>  $state */
    private function assertCanBeActive(array $state): void
    {
        if (($state['status'] ?? null) !== 'active') {
            return;
        }

        if (($state['monthly_price_cents'] ?? null) === null || ($state['yearly_discount_percent'] ?? null) === null) {
            throw ValidationException::withMessages([
                'status' => 'Um plano só pode ficar ativo com preço mensal e desconto anual definidos.',
            ]);
        }
    }
}
