<?php

use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-17 returns bodies that respect the openapi contract', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'suspended', ['suspended_at' => now()->subDay()]);
    addInvoices($ws, 2);
    $this->loadOpenApi('PLT-007-gestao-da-assinatura');

    $regularized = manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'k1')->assertOk();
    expect($this->schemaErrors($regularized->json(), 'ManagedSubscription'))->toBe([]);

    $canceled = manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();
    expect($this->schemaErrors($canceled->json(), 'ManagedSubscription'))->toBe([]);

    $reactivated = manage($this, $token, 'POST', 'neg', 'subscription/reactivate')->assertOk();
    expect($this->schemaErrors($reactivated->json(), 'ManagedSubscription'))->toBe([]);

    $invoices = manage($this, $token, 'GET', 'neg', 'subscription/invoices')->assertOk();
    expect($this->schemaErrors($invoices->json(), 'InvoicePage'))->toBe([]);
});

it('CA-17 returns the documented 402 body when the regularization charge is refused', function () {
    [, $token] = ownerOf($this, 'neg', 'suspended', ['suspended_at' => now()->subDay()]);
    $this->loadOpenApi('PLT-007-gestao-da-assinatura');

    $response = manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_fail_on_charge'], 'k1')->assertStatus(402);

    expect($this->schemaErrors($response->json(), 'PaymentFailed'))->toBe([]);
});

it('CA-17 documents every status the routes can answer', function () {
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-007-gestao-da-assinatura/openapi.yaml'));

    foreach (['/subscription/payment-method' => ['401', '402', '403', '409', '422', '429'], '/subscription/cancel' => ['401', '403', '409'], '/subscription/reactivate' => ['401', '403', '409'], '/subscription/invoices' => ['401', '403', '422']] as $path => $codes) {
        $operation = collect($spec['paths'][$path])->first();
        expect(array_map('strval', array_keys($operation['responses'])))->toContain(...$codes);
    }
});
