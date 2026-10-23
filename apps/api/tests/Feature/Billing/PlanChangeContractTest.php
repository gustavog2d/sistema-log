<?php

use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

it('CA-19 returns bodies that respect the openapi contract', function () {
    [, $token] = planOwner($this);
    $this->loadOpenApi('PLT-013-troca-de-plano');

    $preview = manage($this, $token, 'GET', 'neg', 'subscription/plan-change/preview?plan=essencial')->assertOk();
    expect($this->schemaErrors($preview->json(), 'PlanChangePreview'))->toBe([]);

    $scheduled = changePlan($this, $token, 'neg', 'essencial')->assertOk();
    expect($this->schemaErrors($scheduled->json(), 'PlanChangeResult'))->toBe([]);

    $canceled = manage($this, $token, 'DELETE', 'neg', 'subscription/plan-change')->assertOk();
    expect($this->schemaErrors($canceled->json(), 'SubscriptionState'))->toBe([]);

    $now = changePlan($this, $token, 'neg', 'estudio', 'k2')->assertOk();
    expect($this->schemaErrors($now->json(), 'PlanChangeResult'))->toBe([]);
});

it('CA-19 returns the documented 409 body with the violations', function () {
    [$ws, $token] = planOwner($this);
    planUserLimits($this, ['essencial' => 1, 'profissional' => 5, 'estudio' => null]);
    addPeople($ws, 1);
    $this->loadOpenApi('PLT-013-troca-de-plano');

    $conflict = changePlan($this, $token, 'neg', 'essencial')->assertStatus(409);

    expect($this->schemaErrors($conflict->json(), 'Problem'))->toBe([]);
});
