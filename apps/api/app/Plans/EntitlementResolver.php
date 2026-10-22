<?php

namespace App\Plans;

use App\Models\Feature;
use App\Models\Plan;
use Illuminate\Support\Collection;

/**
 * Única fonte da resposta "o plano permite esta funcionalidade, com que limite, visível ou oculta".
 * Ordem: exceção do assinante, regra do plano, padrão da funcionalidade.
 */
class EntitlementResolver
{
    /**
     * @param  array<string, array{enabled: bool, limit?: int|null, visible: bool}>  $overrides  indexadas pela chave da funcionalidade
     * @param  Collection<int, Feature>|null  $features  catálogo já carregado, para evitar consultas repetidas
     * @return Collection<int, Entitlement>
     */
    public function forPlan(Plan $plan, array $overrides = [], ?Collection $features = null): Collection
    {
        $rules = $plan->featureRules()->get()->keyBy('feature_id');

        return ($features ?? Feature::query()->orderBy('key')->get())->map(function (Feature $feature) use ($rules, $overrides): Entitlement {
            if (isset($overrides[$feature->key])) {
                $o = $overrides[$feature->key];

                return $this->build($feature, $o['enabled'], $o['limit'] ?? null, $o['visible'], 'override');
            }

            $rule = $rules->get($feature->id);

            if ($rule !== null) {
                return $this->build($feature, $rule->enabled, $rule->limit, $rule->visible, 'plan');
            }

            return $this->build($feature, $feature->default_enabled, $feature->default_limit, $feature->default_visible, 'default');
        })->values();
    }

    private function build(Feature $feature, bool $enabled, ?int $limit, bool $visible, string $source): Entitlement
    {
        return new Entitlement(
            feature: $feature->key,
            enabled: $enabled,
            // Funcionalidade liberar/bloquear não tem limite numérico.
            limit: $feature->kind === 'limit' ? $limit : null,
            // Liberada e oculta não faz sentido para a interface: uma funcionalidade liberada aparece.
            visible: $enabled ? true : $visible,
            source: $source,
        );
    }
}
