<?php

use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, mixed>
 */
function healthContract(): array
{
    $path = base_path('../../docs/funcionalidades/FND-002-monorepo-e-agentes/openapi.yaml');

    /** @var array<string, mixed> */
    return Yaml::parseFile($path);
}

/**
 * @return list<string>
 */
function propertyNames(mixed $schema): array
{
    return array_map('strval', array_keys(is_array($schema) ? $schema : []));
}

it('documents the health route in the openapi contract', function () {
    expect(healthContract()['paths'])->toHaveKey('/health');
});

it('returns a body that matches the openapi contract', function () {
    $schema = data_get(healthContract(), 'components.schemas.Health.properties');

    $body = $this->getJson('/api/v1/health')->assertOk()->json();

    expect(propertyNames($body))->toEqualCanonicalizing(propertyNames($schema));
    expect(propertyNames(data_get($body, 'checks')))
        ->toEqualCanonicalizing(propertyNames(data_get($schema, 'checks.properties')));
});
