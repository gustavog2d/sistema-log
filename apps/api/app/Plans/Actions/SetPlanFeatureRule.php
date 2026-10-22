<?php

namespace App\Plans\Actions;

use App\Models\Feature;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlatformUser;
use App\Plans\Entitlement;
use App\Plans\EntitlementResolver;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Define ou remove a regra de um plano para uma funcionalidade, com auditoria na mesma transação. */
class SetPlanFeatureRule
{
    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly EntitlementResolver $resolver,
    ) {}

    /** @param  array{enabled: bool, limit?: int|null, visible: bool}  $data */
    public function set(PlatformUser $actor, Plan $plan, Feature $feature, array $data): Entitlement
    {
        if ($feature->kind === 'toggle' && ($data['limit'] ?? null) !== null) {
            throw ValidationException::withMessages(['limit' => 'Funcionalidade de liberar/bloquear não aceita limite.']);
        }

        $after = ['enabled' => $data['enabled'], 'limit' => $data['limit'] ?? null, 'visible' => $data['visible']];

        DB::transaction(function () use ($actor, $plan, $feature, $after): void {
            $before = PlanFeature::query()->where(['plan_id' => $plan->id, 'feature_id' => $feature->id])->lockForUpdate()->first()?->only(['enabled', 'limit', 'visible']);

            // Upsert atômico: dois pedidos simultâneos não esbarram na chave única.
            PlanFeature::query()->upsert(
                [['id' => (string) Str::uuid(), 'plan_id' => $plan->id, 'feature_id' => $feature->id, ...$after, 'created_at' => now(), 'updated_at' => now()]],
                ['plan_id', 'feature_id'],
                ['enabled', 'limit', 'visible', 'updated_at'],
            );

            $this->audit->record('plan_feature.set', $actor, [
                'plan' => $plan->id, 'feature' => $feature->key, 'before' => $before, 'after' => $after,
            ]);
        });

        return $this->resolver->forPlan($plan)->firstWhere('feature', $feature->key)
            ?? throw new \LogicException('Funcionalidade ausente do cálculo.');
    }

    public function reset(PlatformUser $actor, Plan $plan, Feature $feature): void
    {
        DB::transaction(function () use ($actor, $plan, $feature): void {
            $rule = PlanFeature::query()->where(['plan_id' => $plan->id, 'feature_id' => $feature->id])->first();

            if ($rule === null) {
                return;
            }

            $before = $rule->only(['enabled', 'limit', 'visible']);
            $rule->delete();
            $this->audit->record('plan_feature.reset', $actor, ['plan' => $plan->id, 'feature' => $feature->key, 'before' => $before]);
        });
    }
}
