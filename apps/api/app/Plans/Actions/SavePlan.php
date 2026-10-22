<?php

namespace App\Plans\Actions;

use App\Models\Plan;
use App\Models\PlatformUser;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cria e altera planos. Concentra as regras de status e grava a auditoria na mesma transação. */
class SavePlan
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(PlatformUser $actor, array $data): Plan
    {
        $this->assertCanBeActive($data);

        return DB::transaction(function () use ($actor, $data): Plan {
            $plan = Plan::create($data)->refresh();
            $this->audit->record('plan.created', $actor, ['plan' => $plan->id, 'after' => $plan->toApi()]);

            return $plan;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PlatformUser $actor, Plan $plan, array $data): Plan
    {
        return DB::transaction(function () use ($actor, $plan, $data): Plan {
            // Linha travada: duas alterações simultâneas não deixam o plano ativo sem preço.
            $locked = Plan::query()->lockForUpdate()->findOrFail($plan->id);

            $this->assertStatusTransition($locked, $data);
            $this->assertCanBeActive($data + $locked->only(['status', 'monthly_price_cents', 'yearly_discount_percent']));

            $locked->fill($data);
            $changes = $locked->getDirty();

            if ($changes !== []) {
                $before = array_intersect_key($locked->getOriginal(), $changes);
                $locked->save();
                $this->audit->record('plan.updated', $actor, ['plan' => $locked->id, 'before' => $before, 'after' => $changes]);
            }

            $plan->setRawAttributes($locked->getAttributes(), true);

            return $plan;
        });
    }

    /**
     * RN-03: só fica ativo com preço mensal e desconto anual definidos.
     *
     * @param  array<string, mixed>  $state
     */
    private function assertCanBeActive(array $state): void
    {
        if (($state['status'] ?? 'draft') !== 'active') {
            return;
        }

        if (($state['monthly_price_cents'] ?? null) === null || ($state['yearly_discount_percent'] ?? null) === null) {
            throw ValidationException::withMessages([
                'status' => 'Um plano só pode ficar ativo com preço mensal e desconto anual definidos.',
            ]);
        }
    }

    /**
     * RN-03: plano arquivado é definitivo; não volta a rascunho nem a ativo.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertStatusTransition(Plan $plan, array $data): void
    {
        if ($plan->status === 'archived' && ($data['status'] ?? 'archived') !== 'archived') {
            throw ValidationException::withMessages(['status' => 'Plano arquivado não pode ser reativado.']);
        }
    }
}
