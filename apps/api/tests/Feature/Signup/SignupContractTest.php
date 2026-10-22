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

it('declares every status code and the replay header that the endpoint really returns', function () {
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-003-cadastro-do-assinante/openapi.yaml'));
    $post = data_get($spec, 'paths./signup.post.responses');

    expect(array_map('strval', array_keys($post)))->toContain('201', '409', '422', '429');
    expect(data_get($post, '201.headers'))->toHaveKey('Idempotent-Replayed');
    expect(data_get($spec, 'paths./signup.post.parameters.0.name'))->toBe('Idempotency-Key');

    // Cada código declarado é um código observado nos testes de comportamento.
    $this->seedCatalog();
    $this->signup()->assertCreated();
    $this->signup(key: null)->assertStatus(422);
    $this->signup(['slug' => 'outro', 'owner_email' => 'o@exemplo.com'])->assertStatus(422);
});
