<?php

use App\Models\Plan;
use Database\Factories\PlanFactory;
use Illuminate\Support\Str;

function planPayload(array $overrides = []): array
{
    return array_merge(['key' => 'basico', 'name' => 'Básico', 'position' => 1], $overrides);
}

it('CA-01 lists all plans for the platform team', function () {
    PlanFactory::new()->count(2)->create();

    $this->asToken($this->adminToken(), 'GET', 'plans')->assertOk()->assertJsonCount(2, 'data');
});

it('creates a draft plan with the default trial', function () {
    $this->asToken($this->adminToken(), 'POST', 'plans', planPayload())
        ->assertCreated()
        ->assertJson(['key' => 'basico', 'status' => 'draft', 'trial_days' => 7, 'currency' => 'BRL', 'monthly_price_cents' => null, 'yearly_price_cents' => null]);
});

it('CA-02 returns the computed yearly price', function () {
    $plan = PlanFactory::new()->create(['monthly_price_cents' => 10000, 'yearly_discount_percent' => 20]);

    $this->asToken($this->adminToken(), 'GET', "plans/{$plan->id}")->assertOk()->assertJson(['yearly_price_cents' => 96000]);
});

it('CA-03 returns no yearly price without the discount', function () {
    $plan = PlanFactory::new()->create(['monthly_price_cents' => 10000]);

    $this->asToken($this->adminToken(), 'GET', "plans/{$plan->id}")->assertOk()->assertJson(['yearly_price_cents' => null]);
});

it('CA-04 refuses to activate a plan without price or discount', function () {
    $plan = PlanFactory::new()->create();

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", ['status' => 'active'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", ['status' => 'active', 'monthly_price_cents' => 5000])->assertStatus(422);
});

it('CA-05 activates a plan that has price and discount', function () {
    $plan = PlanFactory::new()->create();

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", [
        'status' => 'active', 'monthly_price_cents' => 5000, 'yearly_discount_percent' => 15,
    ])->assertOk()->assertJson(['status' => 'active', 'yearly_price_cents' => 51000]);
});

it('CA-04 refuses to remove the price of an active plan', function () {
    $plan = PlanFactory::new()->active()->create();

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", ['monthly_price_cents' => null])->assertStatus(422);
});

it('CA-14 validates the plan fields', function (array $payload) {
    $this->asToken($this->adminToken(), 'POST', 'plans', planPayload($payload))
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');
})->with([
    'desconto acima de 100' => [['yearly_discount_percent' => 101]],
    'desconto negativo' => [['yearly_discount_percent' => -1]],
    'preço negativo' => [['monthly_price_cents' => -1]],
    'preço decimal' => [['monthly_price_cents' => 49.9]],
    'status inválido' => [['status' => 'publicado']],
    'teste negativo' => [['trial_days' => -1]],
    'chave inválida' => [['key' => 'Chave Inválida']],
    'sem nome' => [['name' => '']],
]);

it('CA-14 refuses a repeated plan key', function () {
    PlanFactory::new()->create(['key' => 'basico']);

    $this->asToken($this->adminToken(), 'POST', 'plans', planPayload())->assertStatus(422);
});

it('returns 404 problem details for an unknown plan', function () {
    $this->asToken($this->adminToken(), 'GET', 'plans/'.Str::uuid())->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
});

it('keeps the plan key stable when it is already in use by subscriptions later', function () {
    $plan = PlanFactory::new()->create(['key' => 'basico']);

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", ['name' => 'Novo nome'])->assertOk()->assertJson(['key' => 'basico', 'name' => 'Novo nome']);
    expect(Plan::find($plan->id)->name)->toBe('Novo nome');
});
