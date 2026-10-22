<?php

namespace App\Plans\Actions;

use App\Models\Feature;
use App\Models\PlanFeature;
use App\Models\PlatformUser;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Cria e altera funcionalidades do catálogo, com auditoria na mesma transação. */
class SaveFeature
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /** @param  array<string, mixed>  $data */
    public function create(PlatformUser $actor, array $data): Feature
    {
        $this->assertCoherent($data);

        return DB::transaction(function () use ($actor, $data): Feature {
            $feature = Feature::create($data)->refresh();
            $this->audit->record('feature.created', $actor, ['feature' => $feature->key, 'after' => $feature->toApi()]);

            return $feature;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(PlatformUser $actor, Feature $feature, array $data): Feature
    {
        $this->assertCoherent($data + $feature->only(['kind', 'default_limit']));

        return DB::transaction(function () use ($actor, $feature, $data): Feature {
            $feature->fill($data);
            $changes = $feature->getDirty();

            if ($changes !== []) {
                $before = array_intersect_key($feature->getOriginal(), $changes);
                $feature->save();

                // Funcionalidade que vira "liberar/bloquear" não guarda limites em regras já gravadas.
                if ($feature->kind === 'toggle') {
                    PlanFeature::query()->where('feature_id', $feature->id)->whereNotNull('limit')->update(['limit' => null]);
                }

                $this->audit->record('feature.updated', $actor, ['feature' => $feature->key, 'before' => $before, 'after' => $changes]);
            }

            return $feature;
        });
    }

    /**
     * RN-07: funcionalidade de liberar/bloquear não tem limite numérico.
     *
     * @param  array<string, mixed>  $state
     */
    private function assertCoherent(array $state): void
    {
        if (($state['kind'] ?? 'toggle') === 'toggle' && ($state['default_limit'] ?? null) !== null) {
            throw ValidationException::withMessages(['default_limit' => 'Funcionalidade de liberar/bloquear não aceita limite.']);
        }
    }
}
