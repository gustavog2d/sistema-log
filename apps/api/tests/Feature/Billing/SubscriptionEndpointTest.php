<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->seedBillingCatalog();
});

/** Cria uma pessoa vinculada ao negócio e devolve um token de acesso emitido direto no banco (sem passar pelo login). */
function loggedIn($test, string $slug): string
{
    $tenantId = DB::connection('pgsql_admin')->table('tenants')->where('slug', $slug)->value('id');
    $userId = (string) Str::uuid();
    $db = DB::connection('pgsql_admin');
    $db->table('users')->insert(['id' => $userId, 'name' => 'Pessoa', 'email' => $userId.'@exemplo.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $db->table('memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $userId, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    $tokenId = (string) Str::uuid();
    $secret = Str::random(40);
    $db->table('user_access_tokens')->insert(['id' => $tokenId, 'tenant_id' => $tenantId, 'user_id' => $userId, 'kind' => 'access', 'token_hash' => hash('sha256', $secret), 'expires_at' => now()->addHour(), 'created_at' => now()]);

    return $tokenId.'|'.$secret;
}

function statusOf($test, string $slug)
{
    return $test->withToken(loggedIn($test, $slug))->getJson("http://{$slug}.app.test/api/v1/subscription");
}

it('CA-07 shows the status, the plan, the price and the next charge of a trial', function () {
    $this->subscription([
        'status' => 'trialing', 'trial_ends_at' => $this->at('2026-12-01 09:00:00'), 'price_cents' => 5000, 'cycle' => 'monthly',
    ], 'pm_fake_ok_x', 'neg');

    statusOf($this, 'neg')
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

    statusOf($this, 'neg')
        ->assertOk()
        ->assertJsonPath('next_charge_at', '2026-12-01T09:00:00+00:00')
        ->assertJsonPath('current_period_end', '2026-12-01T09:00:00+00:00');
});

it('CA-08 answers a suspended business with its state and no next charge', function () {
    $this->subscription(['status' => 'suspended', 'suspended_at' => $this->at('2026-11-10 12:00:00')], 'pm_fake_ok_x', 'neg');

    statusOf($this, 'neg')
        ->assertOk()
        ->assertJson(['status' => 'suspended', 'can_write' => false, 'next_charge_at' => null])
        ->assertJsonPath('suspended_at', '2026-11-10T12:00:00+00:00');
});

it('CA-09 shows each business only its own subscription', function () {
    $this->subscription(['status' => 'active', 'price_cents' => 1111], 'pm_fake_ok_x', 'primeiro');
    $this->subscription(['status' => 'suspended', 'price_cents' => 2222], 'pm_fake_ok_x', 'segundo');

    statusOf($this, 'primeiro')->assertJson(['price_cents' => 1111, 'status' => 'active']);
    statusOf($this, 'segundo')->assertJson(['price_cents' => 2222, 'status' => 'suspended']);
});

it('answers 404 problem details when the business has no subscription', function () {
    $this->makeTenant('neg');

    statusOf($this, 'neg')->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-11 is not available on the platform host', function () {
    $this->getJson('http://app.test/api/v1/subscription')->assertNotFound();
});

it('returns bodies that respect the schemas of the openapi contract, including types, enums and dates', function () {
    $this->loadOpenApi('PLT-005-bloqueio-por-status');
    $this->subscription(['status' => 'active'], 'pm_fake_ok_x', 'neg');
    $this->subscription(['status' => 'suspended', 'suspended_at' => $this->at('2026-11-10 12:00:00')], 'pm_fake_ok_x', 'susp');
    $this->makeTenant('semassinatura');

    expect($this->schemaErrors(statusOf($this, 'neg')->assertOk()->json(), 'Subscription'))->toBe([]);
    expect($this->schemaErrors(statusOf($this, 'susp')->assertOk()->json(), 'Subscription'))->toBe([]);
    expect($this->schemaErrors(statusOf($this, 'semassinatura')->assertNotFound()->json(), 'Problem'))->toBe([]);
});

it('returns a blocked write that respects the SubscriptionBlocked schema and the referenced response', function () {
    $this->loadOpenApi('PLT-005-bloqueio-por-status');
    $this->subscription(['status' => 'suspended'], 'pm_fake_ok_x', 'neg');
    Route::middleware(['tenant', 'subscription.access'])->post('api/v1/_probe/escrita', fn () => []);

    $body = $this->postJson('http://neg.app.test/api/v1/_probe/escrita')->assertForbidden()->json();

    expect($this->schemaErrors($body, 'SubscriptionBlocked'))->toBe([]);
    expect(data_get(Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-005-bloqueio-por-status/openapi.yaml')), 'components.responses.SubscriptionBlocked.content.application/problem+json.schema.$ref'))
        ->toBe('#/components/schemas/SubscriptionBlocked');
});

it('the validator really catches a wrong type, a missing field and a value outside the enum', function () {
    $this->loadOpenApi('PLT-005-bloqueio-por-status');

    $errors = $this->schemaErrors(['status' => 'pausada', 'can_write' => 'sim', 'plan' => ['key' => 'x']], 'Subscription');

    expect(implode(' | ', $errors))->toContain('valor fora do enum')->toContain('falta cycle')->toContain('can_write: tipo string');
});
