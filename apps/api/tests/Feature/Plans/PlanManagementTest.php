<?php

use App\Models\Plan;
use App\Models\PlatformUser;
use App\Plans\Actions\SavePlan;
use App\Platform\Auth\PlatformAudit;
use Database\Factories\PlanFactory;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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

it('updates the plan name without touching the key', function () {
    $plan = PlanFactory::new()->create(['key' => 'basico']);

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", ['name' => 'Novo nome'])->assertOk()->assertJson(['key' => 'basico', 'name' => 'Novo nome']);
    expect(Plan::find($plan->id)->name)->toBe('Novo nome');
});

it('CA-14 validates the plan fields on update too', function (array $payload) {
    $plan = PlanFactory::new()->create();

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", $payload)
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');
})->with([
    'desconto acima de 100' => [['yearly_discount_percent' => 101]],
    'preço negativo' => [['monthly_price_cents' => -1]],
    'preço acima do limite' => [['monthly_price_cents' => 100_000_001]],
    'status inválido' => [['status' => 'publicado']],
    'nome vazio' => [['name' => '']],
    'chave não pode mudar' => [['key' => 'outra-chave']],
]);

it('RN-01 refuses to change the key even to the same value', function () {
    $plan = PlanFactory::new()->create(['key' => 'basico']);

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", ['key' => 'basico'])->assertStatus(422);
});

it('CA-14 refuses an out of range price on creation', function () {
    $this->asToken($this->adminToken(), 'POST', 'plans', planPayload(['monthly_price_cents' => 2_147_483_648]))->assertStatus(422);
});

it('RN-03 is also enforced by the database', function () {
    $connection = DB::connection('pgsql_admin');

    expect(fn () => $connection->table('plans')->insert([
        'id' => (string) Str::uuid(), 'key' => 'x', 'name' => 'X', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(fn () => $connection->table('plans')->insert([
        'id' => (string) Str::uuid(), 'key' => 'y', 'name' => 'Y', 'status' => 'publicado', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('RN-09 rolls the change back when the audit cannot be written', function () {
    $plan = PlanFactory::new()->create(['name' => 'Original']);
    $token = $this->adminToken();

    $this->app->bind(PlatformAudit::class, fn () => new class(request()) extends PlatformAudit
    {
        public function record(string $event, ?PlatformUser $user = null, array $metadata = []): void
        {
            throw new RuntimeException('falha na auditoria');
        }
    });

    $this->asToken($token, 'PATCH', "plans/{$plan->id}", ['name' => 'Alterado'])->assertStatus(500);

    expect(Plan::find($plan->id)->name)->toBe('Original');
});

it('CA-14 refuses a position beyond the smallint column', function () {
    $this->asToken($this->adminToken(), 'POST', 'plans', planPayload(['position' => 32768]))->assertStatus(422);
});

it('RN-03 does not let an archived plan come back', function () {
    $plan = PlanFactory::new()->active()->create();
    $token = $this->adminToken();

    $this->asToken($token, 'PATCH', "plans/{$plan->id}", ['status' => 'archived'])->assertOk();
    $this->asToken($token, 'PATCH', "plans/{$plan->id}", ['status' => 'active'])->assertStatus(422);
    $this->asToken($token, 'PATCH', "plans/{$plan->id}", ['status' => 'draft'])->assertStatus(422);
});

it('RN-03 decides on the fresh row, not on a stale copy, when activating', function () {
    $plan = PlanFactory::new()->create(['monthly_price_cents' => 5000, 'yearly_discount_percent' => 10]);
    $stale = Plan::find($plan->id);
    $admin = $this->platformUser();

    app(SavePlan::class)->update($admin, Plan::find($plan->id), ['monthly_price_cents' => null]);

    expect(fn () => app(SavePlan::class)->update($admin, $stale, ['status' => 'active']))
        ->toThrow(ValidationException::class);
    expect(Plan::find($plan->id)->status)->toBe('draft');
});

it('RN-09 audits only what actually changed and skips no-op updates', function () {
    $plan = PlanFactory::new()->create(['name' => 'Igual']);
    $token = $this->adminToken();

    $this->asToken($token, 'PATCH', "plans/{$plan->id}", [])->assertOk();
    $this->asToken($token, 'PATCH', "plans/{$plan->id}", ['name' => 'Igual'])->assertOk();
    expect(DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'plan.updated')->count())->toBe(0);

    $this->asToken($token, 'PATCH', "plans/{$plan->id}", ['name' => 'Diferente', 'trial_days' => 7])->assertOk();
    $metadata = json_decode(DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'plan.updated')->value('metadata'), true);

    expect($metadata['before'])->toBe(['name' => 'Igual'])->and($metadata['after'])->toBe(['name' => 'Diferente']);
});

it('answers an unexpected failure as a bare problem details response when debug is off', function () {
    config(['app.debug' => false]);
    $plan = PlanFactory::new()->create();
    $token = $this->adminToken();

    $this->app->bind(PlatformAudit::class, fn () => new class(request()) extends PlatformAudit
    {
        public function record(string $event, ?PlatformUser $user = null, array $metadata = []): void
        {
            throw new RuntimeException('segredo interno SQL select * from');
        }
    });

    $response = $this->asToken($token, 'PATCH', "plans/{$plan->id}", ['name' => 'Novo']);

    $response->assertStatus(500)->assertHeader('Content-Type', 'application/problem+json');
    expect($response->getContent())->not->toContain('segredo interno')->not->toContain('trace')->not->toContain('file');
});
