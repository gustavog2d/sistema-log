<?php

use Database\Factories\FeatureFactory;
use Database\Factories\PlanFactory;

it('CA-12 lists only active plans, in order, without internal fields', function () {
    PlanFactory::new()->active()->create(['key' => 'b', 'name' => 'B', 'position' => 2, 'monthly_price_cents' => 9000, 'yearly_discount_percent' => 10]);
    PlanFactory::new()->active()->create(['key' => 'a', 'name' => 'A', 'position' => 1, 'monthly_price_cents' => 5000, 'yearly_discount_percent' => 20]);
    PlanFactory::new()->create(['key' => 'rascunho', 'position' => 3]);
    PlanFactory::new()->create(['key' => 'arquivado', 'status' => 'archived', 'position' => 4]);

    $response = $this->getJson('http://app.test/api/v1/plans')->assertOk();

    expect(collect($response->json('data'))->pluck('key')->all())->toBe(['a', 'b']);
    expect($response->json('data.0'))->toMatchArray(['monthly_price_cents' => 5000, 'yearly_price_cents' => 48000, 'trial_days' => 7]);
    expect(array_keys($response->json('data.0')))->not->toContain('id', 'status', 'yearly_discount_percent');
});

it('CA-12 shows only visible features and never leaks hidden ones', function () {
    $plan = PlanFactory::new()->active()->create();
    FeatureFactory::new()->create(['key' => 'orcamento']);
    $hidden = FeatureFactory::new()->create(['key' => 'segredo']);
    $plan->featureRules()->create(['feature_id' => $hidden->id, 'enabled' => false, 'limit' => null, 'visible' => false]);

    $features = collect($this->getJson('http://app.test/api/v1/plans')->json('data.0.features'));

    expect($features->pluck('feature')->all())->toBe(['orcamento']);
    expect(array_keys($features->first()))->toEqualCanonicalizing(['feature', 'enabled', 'limit']);
});

it('CA-12 is reachable without a token and also from a tenant host', function () {
    $this->makeTenant('acme');
    PlanFactory::new()->active()->create();

    $this->getJson('http://app.test/api/v1/plans')->assertOk();
    $this->getJson('http://acme.app.test/api/v1/plans')->assertOk();
});

it('CA-12 returns an empty list when nothing is active', function () {
    PlanFactory::new()->create();

    $this->getJson('http://app.test/api/v1/plans')->assertOk()->assertExactJson(['data' => []]);
});
