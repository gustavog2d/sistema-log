<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(fn () => $this->seedBillingCatalog());

it('CA-07 shows the status, the plan, the price and the next charge of a trial', function () {
    $this->subscription([
        'status' => 'trialing', 'trial_ends_at' => $this->at('2026-12-01 09:00:00'), 'price_cents' => 5000, 'cycle' => 'monthly',
    ], 'pm_fake_ok_x', 'neg');

    $this->getJson('http://neg.app.test/api/v1/subscription')
        ->assertOk()
        ->assertJson([
            'status' => 'trialing', 'plan' => ['key' => 'profissional', 'name' => 'Profissional'], 'cycle' => 'monthly',
            'price_cents' => 5000, 'currency' => 'BRL', 'suspended_at' => null, 'can_write' => true,
        ])
        ->assertJsonPath('trial_ends_at', '2026-12-01T09:00:00+00:00')
        ->assertJsonPath('next_charge_at', '2026-12-01T09:00:00+00:00');
});

it('CA-07 points the next charge of an active subscription to the end of the period', function () {
    $this->subscription([
        'status' => 'active', 'current_period_start' => $this->at('2026-11-01 09:00:00'), 'current_period_end' => $this->at('2026-12-01 09:00:00'),
    ], 'pm_fake_ok_x', 'neg');

    $this->getJson('http://neg.app.test/api/v1/subscription')
        ->assertOk()
        ->assertJsonPath('next_charge_at', '2026-12-01T09:00:00+00:00')
        ->assertJsonPath('current_period_end', '2026-12-01T09:00:00+00:00');
});

it('CA-08 answers a suspended business with its state and no next charge', function () {
    $this->subscription(['status' => 'suspended', 'suspended_at' => $this->at('2026-11-10 12:00:00')], 'pm_fake_ok_x', 'neg');

    $this->getJson('http://neg.app.test/api/v1/subscription')
        ->assertOk()
        ->assertJson(['status' => 'suspended', 'can_write' => false, 'next_charge_at' => null])
        ->assertJsonPath('suspended_at', '2026-11-10T12:00:00+00:00');
});

it('CA-09 shows each business only its own subscription', function () {
    $this->subscription(['status' => 'active', 'price_cents' => 1111], 'pm_fake_ok_x', 'primeiro');
    $this->subscription(['status' => 'suspended', 'price_cents' => 2222], 'pm_fake_ok_x', 'segundo');

    $this->getJson('http://primeiro.app.test/api/v1/subscription')->assertJson(['price_cents' => 1111, 'status' => 'active']);
    $this->getJson('http://segundo.app.test/api/v1/subscription')->assertJson(['price_cents' => 2222, 'status' => 'suspended']);
});

it('answers 404 problem details when the business has no subscription', function () {
    $this->makeTenant('neg');

    $this->getJson('http://neg.app.test/api/v1/subscription')->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-11 is not available on the platform host', function () {
    $this->getJson('http://app.test/api/v1/subscription')->assertNotFound();
});

it('returns a body that matches the openapi contract', function () {
    $this->subscription(['status' => 'active'], 'pm_fake_ok_x', 'neg');
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-005-bloqueio-por-status/openapi.yaml'));
    $keys = fn (string $path): array => array_map('strval', array_keys(data_get($spec, $path)));

    $body = $this->getJson('http://neg.app.test/api/v1/subscription')->assertOk()->json();

    expect(array_keys($body))->toEqualCanonicalizing($keys('components.schemas.Subscription.properties'));
    expect(array_keys($body['plan']))->toEqualCanonicalizing($keys('components.schemas.Subscription.properties.plan.properties'));
});

it('declares the blocked write problem in the contract', function () {
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-005-bloqueio-por-status/openapi.yaml'));

    expect(data_get($spec, 'components.schemas.SubscriptionBlocked.properties.code.enum'))->toBe(['subscription_suspended', 'subscription_canceled']);
});
