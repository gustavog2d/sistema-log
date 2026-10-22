<?php

beforeEach(function () {
    $this->seedBillingCatalog();
});

const MANAGE_ROUTES = [
    ['PUT', 'subscription/payment-method', ['payment_method_token' => 'tok_ok']],
    ['POST', 'subscription/cancel', []],
    ['POST', 'subscription/reactivate', []],
    ['GET', 'subscription/invoices', []],
];

it('CA-01 refuses every role but the owner', function (string $role) {
    $this->workspace('neg', 'dona@exemplo.com');
    $this->workspace('neg2', 'outra@exemplo.com'); // só para garantir que existe mais de um negócio
    $this->workspace('neg3', 'papel@exemplo.com', $role);
    $token = $this->tokenFor('neg3', 'papel@exemplo.com');

    foreach (MANAGE_ROUTES as [$method, $path, $body]) {
        manage($this, $token, $method, 'neg3', $path, $body, 'k-'.$path)->assertForbidden();
    }
})->with(['manager', 'professional', 'reception']);

it('CA-01 asks for a login on every new route', function () {
    $this->workspace('neg');

    foreach (MANAGE_ROUTES as [$method, $path, $body]) {
        $this->withHeaders(['Idempotency-Key' => 'k'])->json($method, "http://neg.app.test/api/v1/{$path}", $body)->assertUnauthorized();
    }
});

it('CA-16 does not let the token of one business act on another', function () {
    [, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob', 'active');

    manage($this, $tokenA, 'POST', 'negociob', 'subscription/cancel')->assertUnauthorized();
    expect(subscriptionOf($b)->canceled_at)->toBeNull();
});

it('CA-16 only changes the subscription of the business in the host', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');

    manage($this, $tokenA, 'POST', 'negocioa', 'subscription/cancel')->assertOk();

    expect(subscriptionOf($a)->canceled_at)->not->toBeNull()
        ->and(subscriptionOf($b)->canceled_at)->toBeNull();
});

it('lets the owner manage a suspended and a canceled subscription (routes are exempt from the write block)', function (string $status) {
    [, $token] = ownerOf($this, 'neg', $status);

    manage($this, $token, 'GET', 'neg', 'subscription/invoices')->assertOk();
    manage($this, $token, 'POST', 'neg', 'subscription/reactivate')->assertStatus(409); // chegou na regra, não no bloqueio 403
})->with(['suspended', 'canceled']);
