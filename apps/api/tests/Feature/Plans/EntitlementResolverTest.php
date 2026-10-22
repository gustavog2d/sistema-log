<?php

use App\Models\Feature;
use App\Models\Plan;
use App\Plans\EntitlementResolver;
use Database\Factories\FeatureFactory;
use Database\Factories\PlanFactory;

function entitlementOf(Plan $plan, string $featureKey, array $overrides = []): array
{
    $entitlement = app(EntitlementResolver::class)->forPlan($plan, $overrides)->firstWhere('feature', $featureKey);

    return ['enabled' => $entitlement->enabled, 'limit' => $entitlement->limit, 'visible' => $entitlement->visible, 'source' => $entitlement->source];
}

beforeEach(function () {
    $this->plan = PlanFactory::new()->create();
    $this->feature = FeatureFactory::new()->create(['key' => 'orcamento', 'kind' => 'toggle']);
    $this->limited = FeatureFactory::new()->create(['key' => 'usuarios.max', 'kind' => 'limit']);
});

it('CA-06 uses the feature default when the plan has no rule', function () {
    expect(entitlementOf($this->plan, 'orcamento'))->toBe(['enabled' => true, 'limit' => null, 'visible' => true, 'source' => 'default']);
});

it('CA-06 uses a restrictive default too', function () {
    $this->feature->update(['default_enabled' => false, 'default_visible' => false]);

    expect(entitlementOf($this->plan, 'orcamento'))->toBe(['enabled' => false, 'limit' => null, 'visible' => false, 'source' => 'default']);
});

it('CA-07 returns an enabled feature with a limit', function () {
    $this->plan->featureRules()->create(['feature_id' => $this->limited->id, 'enabled' => true, 'limit' => 5, 'visible' => true]);

    expect(entitlementOf($this->plan, 'usuarios.max'))->toBe(['enabled' => true, 'limit' => 5, 'visible' => true, 'source' => 'plan']);
});

it('CA-08 returns a blocked but visible feature', function () {
    $this->plan->featureRules()->create(['feature_id' => $this->feature->id, 'enabled' => false, 'limit' => null, 'visible' => true]);

    expect(entitlementOf($this->plan, 'orcamento'))->toBe(['enabled' => false, 'limit' => null, 'visible' => true, 'source' => 'plan']);
});

it('CA-09 returns a blocked and hidden feature', function () {
    $this->plan->featureRules()->create(['feature_id' => $this->feature->id, 'enabled' => false, 'limit' => null, 'visible' => false]);

    expect(entitlementOf($this->plan, 'orcamento'))->toBe(['enabled' => false, 'limit' => null, 'visible' => false, 'source' => 'plan']);
});

it('CA-15 lets a tenant exception override the plan', function () {
    $this->plan->featureRules()->create(['feature_id' => $this->feature->id, 'enabled' => false, 'limit' => null, 'visible' => false]);

    $result = entitlementOf($this->plan, 'orcamento', ['orcamento' => ['enabled' => true, 'limit' => null, 'visible' => true]]);

    expect($result)->toBe(['enabled' => true, 'limit' => null, 'visible' => true, 'source' => 'override']);
});

it('RN-07 ignores a limit on a toggle feature', function () {
    $this->plan->featureRules()->create(['feature_id' => $this->feature->id, 'enabled' => true, 'limit' => 9, 'visible' => true]);

    expect(entitlementOf($this->plan, 'orcamento')['limit'])->toBeNull();
});

it('RN-07 never leaves a hidden feature enabled', function () {
    $this->plan->featureRules()->create(['feature_id' => $this->feature->id, 'enabled' => true, 'limit' => null, 'visible' => false]);

    // Funcionalidade liberada e oculta é incoerente para a interface: o cálculo a mostra como visível.
    expect(entitlementOf($this->plan, 'orcamento')['visible'])->toBeTrue();
});

it('lists every feature of the catalog for a plan', function () {
    $keys = app(EntitlementResolver::class)->forPlan($this->plan)->pluck('feature')->sort()->values()->all();

    expect($keys)->toBe(['orcamento', 'usuarios.max']);
    expect(Feature::count())->toBe(2);
});
