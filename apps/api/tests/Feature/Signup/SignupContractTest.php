<?php

use Symfony\Component\Yaml\Yaml;

it('returns bodies that match the openapi contract', function () {
    $this->seedCatalog();
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-003-cadastro-do-assinante/openapi.yaml'));
    $keys = fn (string $path): array => array_map('strval', array_keys(data_get($spec, $path)));

    $body = $this->signup()->assertCreated()->json();

    expect(array_keys($body))->toEqualCanonicalizing($keys('components.schemas.SignupResult.properties'));
    expect(array_keys($body['tenant']))->toEqualCanonicalizing($keys('components.schemas.SignupResult.properties.tenant.properties'));
    expect(array_keys($body['subscription']))->toEqualCanonicalizing($keys('components.schemas.SignupResult.properties.subscription.properties'));
    expect(array_keys($body['payment_method']))->toEqualCanonicalizing($keys('components.schemas.SignupResult.properties.payment_method.properties'));
});
