<?php

use Database\Factories\FeatureFactory;
use Database\Factories\PlanFactory;
use Symfony\Component\Yaml\Yaml;

function planSchemaKeys(string $schema): array
{
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-002-catalogo-de-planos/openapi.yaml'));

    return array_map('strval', array_keys(data_get($spec, "components.schemas.{$schema}.properties")));
}

it('returns bodies that match the openapi contract', function () {
    $plan = PlanFactory::new()->active()->create();
    $feature = FeatureFactory::new()->create();
    $token = $this->adminToken();

    expect(array_keys($this->asToken($token, 'GET', "plans/{$plan->id}")->json()))->toEqualCanonicalizing(planSchemaKeys('Plan'));
    expect(array_keys($this->asToken($token, 'GET', 'plans')->json('data.0')))->toEqualCanonicalizing(planSchemaKeys('Plan'));
    expect(array_keys($this->asToken($token, 'GET', 'features')->json('data.0')))->toEqualCanonicalizing(planSchemaKeys('Feature'));

    $entitlement = $this->asToken($token, 'PUT', "plans/{$plan->id}/features/{$feature->id}", ['enabled' => true, 'visible' => true]);
    expect(array_keys($entitlement->json()))->toEqualCanonicalizing(planSchemaKeys('Entitlement'));

    $public = $this->getJson('http://app.test/api/v1/plans')->json('data.0');
    expect(array_keys($public))->toEqualCanonicalizing(planSchemaKeys('PublicPlan'));
    expect(array_keys($public['features'][0]))->toEqualCanonicalizing(planSchemaKeys('PublicEntitlement'));
});
