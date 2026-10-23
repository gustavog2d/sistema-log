<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->seedCatalog();
    $this->loadOpenApi('PLT-012-cupons-e-cortesia');
});

it('CA-15 returns bodies that respect the openapi contract', function () {
    $admin = $this->adminToken();

    $created = $this->asToken($admin, 'POST', 'coupons', ['code' => 'CONTRATO', 'percent_off' => 30, 'valid_until' => '2027-01-01T00:00:00Z', 'max_redemptions' => 2, 'description' => 'x'])->assertCreated();
    expect($this->schemaErrors($created->json(), 'Coupon'))->toBe([]);

    $updated = $this->asToken($admin, 'PATCH', 'coupons/'.$created->json('id'), ['status' => 'inactive'])->assertOk();
    expect($this->schemaErrors($updated->json(), 'Coupon'))->toBe([]);

    $list = $this->asToken($admin, 'GET', 'coupons')->assertOk();
    expect($this->schemaErrors($list->json('data.0'), 'Coupon'))->toBe([]);

    makeCoupon(['code' => 'PROMO20']);
    $quote = $this->postJson('http://app.test/api/v1/signup/coupon-check', ['code' => 'PROMO20', 'plan' => 'profissional', 'cycle' => 'monthly'])->assertOk();
    expect($this->schemaErrors($quote->json(), 'CouponQuote'))->toBe([]);

    $invalid = $this->postJson('http://app.test/api/v1/signup/coupon-check', ['code' => 'NADA', 'plan' => 'profissional', 'cycle' => 'monthly'])->assertUnprocessable();
    expect($this->schemaErrors($invalid->json(), 'Problem'))->toBe([]);
});

it('CA-15 documents every status the routes can answer', function () {
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-012-cupons-e-cortesia/openapi.yaml'));
    $expected = [
        '/platform/coupons' => ['get' => ['200', '401', '403'], 'post' => ['201', '401', '403', '422']],
        '/platform/coupons/{id}' => ['patch' => ['200', '401', '403', '404', '422']],
        '/signup/coupon-check' => ['post' => ['200', '422', '429']],
    ];

    foreach ($expected as $path => $operations) {
        foreach ($operations as $method => $codes) {
            expect(array_map('strval', array_keys($spec['paths'][$path][$method]['responses'])))->toContain(...$codes);
        }
    }
});

it('CA-15 returns the signup body with the coupon respecting the signup contract', function () {
    makeCoupon(['code' => 'PROMO20']);
    $this->loadOpenApi('PLT-003-cadastro-do-assinante');

    $response = $this->signup(['coupon_code' => 'PROMO20'])->assertCreated();

    expect($this->schemaErrors($response->json(), 'SignupResult'))->toBe([]);
});
