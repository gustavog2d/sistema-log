<?php

use App\Billing\PaymentGateway;
use App\Http\Middleware\IdempotentRequest;
use App\Platform\Auth\PlatformAudit;
use App\Signup\SignupData;
use App\Signup\SignupService;
use App\Signup\SlugAvailability;
use App\Tenancy\TenantDatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function normalizedBody(array $body): array
{
    ksort($body);

    return $body;
}

beforeEach(fn () => $this->seedCatalog());

it('CA-13 replays the same response for the same key and body, creating one business only', function () {
    $first = $this->signup(key: 'chave-repetida')->assertCreated();
    $second = $this->signup(key: 'chave-repetida')->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect($second->json())->toBe($first->json());
    expect($first->headers->has('Idempotent-Replayed'))->toBeFalse();
    expect($this->countAll('tenants'))->toBe(1)->and($this->countAll('subscriptions'))->toBe(1);
});

it('CA-14 refuses the same key with a different body', function () {
    $this->signup(key: 'chave-repetida')->assertCreated();

    $this->signup(['slug' => 'outroendereco', 'owner_email' => 'b@exemplo.com'], 'chave-repetida')->assertStatus(422);
    expect($this->countAll('tenants'))->toBe(1);
});

it('CA-15 requires the Idempotency-Key header', function () {
    $this->signup(key: null)->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
    $this->nothingWasCreated();
});

it('CA-15 refuses an absurdly long key', function () {
    $this->signup(key: str_repeat('a', 256))->assertStatus(422);
});

it('CA-16 does not let the api role write platform billing tables, and proves it is a permission error', function (string $table, array $row) {
    $error = null;

    try {
        DB::table($table)->insert($table === 'idempotency_keys' ? $row : $row + ['id' => (string) Str::uuid()]);
    } catch (QueryException $e) {
        $error = $e;
    }

    expect($error)->not->toBeNull();
    expect($error->getCode())->toBe('42501');
})->with(fn () => [
    'tenants' => ['tenants', ['name' => 'x', 'slug' => 'xyz', 'status' => 'active', 'timezone' => 'UTC', 'currency' => 'BRL', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']],
    'subscriptions' => ['subscriptions', ['tenant_id' => (string) Str::uuid(), 'plan_id' => (string) Str::uuid(), 'cycle' => 'monthly', 'status' => 'trialing', 'price_cents' => 1500, 'currency' => 'BRL', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']],
    'payment_methods' => ['payment_methods', ['tenant_id' => (string) Str::uuid(), 'gateway_ref' => 'pm_x', 'type' => 'card', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']],
    'users' => ['users', ['name' => 'x', 'email' => 'x@y.co', 'password' => 'x', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']],
    'idempotency_keys' => ['idempotency_keys', ['scope' => 'x', 'key' => 'y', 'request_hash' => 'z']],
]);

it('CA-16 still lets the api role read those tables', function () {
    foreach (['tenants', 'subscriptions', 'payment_methods', 'users', 'idempotency_keys'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
});

it('CA-17 rolls everything back and removes the gateway customer when a later step fails', function () {
    // E-mail já existe, mas a checagem prévia não o vê (corrida): o banco recusa depois de criar o negócio.
    DB::connection('pgsql_admin')->table('users')->insert([
        'id' => (string) Str::uuid(), 'name' => 'Existente', 'email' => 'ana@exemplo.com', 'password' => 'x',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->app->bind(SignupService::class, fn () => new class(app(PaymentGateway::class), app(PlatformAudit::class), app(SlugAvailability::class)) extends SignupService
    {
        protected function assertAvailable(SignupData $data): void {}
    });

    $this->signup()->assertStatus(422)->assertJsonValidationErrors('owner_email');

    foreach (['tenants', 'memberships', 'subscriptions', 'payment_methods'] as $table) {
        expect($this->countAll($table))->toBe(0, "{$table} deveria estar vazia");
    }
    expect(app(PaymentGateway::class)->activeCustomers())->toBe([]);
    expect(DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'signup.completed')->count())->toBe(0);
});

it('B3 answers 422, not 500, when two signups race for the same address', function () {
    DB::connection('pgsql_admin')->table('tenants')->insert([
        'id' => (string) Str::uuid(), 'name' => 'Primeiro', 'slug' => 'tintaviva', 'status' => 'active',
        'timezone' => 'UTC', 'currency' => 'BRL', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->app->bind(SignupService::class, fn () => new class(app(PaymentGateway::class), app(PlatformAudit::class), app(SlugAvailability::class)) extends SignupService
    {
        protected function assertAvailable(SignupData $data): void {}
    });

    $this->signup()->assertStatus(422)->assertJsonValidationErrors('slug');
    expect($this->countAll('users'))->toBe(0)->and(app(PaymentGateway::class)->activeCustomers())->toBe([]);
});

it('B3 removes the gateway customer when the card is declined', function () {
    $this->signup(['payment_method_token' => 'tok_declined'])->assertStatus(422);

    expect(app(PaymentGateway::class)->activeCustomers())->toBe([]);
});

it('keeps exactly one active gateway customer after a successful signup', function () {
    $this->signup()->assertCreated();

    expect(app(PaymentGateway::class)->activeCustomers())->toHaveCount(1);
});

it('B2 answers 409 while the same request is still being processed', function () {
    $body = $this->signupPayload();
    DB::connection('pgsql_admin')->table('idempotency_keys')->insert([
        'scope' => 'signup', 'key' => 'em-andamento', 'created_at' => now(),
        'request_hash' => hash('sha256', json_encode(normalizedBody($body))),
    ]);

    $this->signup(key: 'em-andamento')->assertStatus(409)->assertHeader('Content-Type', 'application/problem+json');
    $this->nothingWasCreated();
});

it('S3 lets a retry through when an in-flight reservation was abandoned', function () {
    DB::connection('pgsql_admin')->table('idempotency_keys')->insert([
        'scope' => 'signup', 'key' => 'abandonada', 'request_hash' => 'qualquer',
        'created_at' => now()->subSeconds(IdempotentRequest::STALE_AFTER_SECONDS + 10),
    ]);

    $this->signup(key: 'abandonada')->assertCreated();
});

it('S3 treats the same body with keys in another order as the same request', function () {
    $first = $this->signup(key: 'ordem')->assertCreated();
    $reordered = array_reverse($this->signupPayload(), true);

    $second = $this->postJson('http://app.test/api/v1/signup', $reordered, ['Idempotency-Key' => 'ordem'])->assertCreated();

    expect($second->json())->toBe($first->json());
    expect($this->countAll('tenants'))->toBe(1);
});

it('RN-13 refuses a coupon code until coupons exist', function () {
    $this->signup(['coupon_code' => 'PROMO100'])->assertStatus(422)->assertJsonValidationErrors('coupon_code');
    $this->nothingWasCreated();
});

it('CA-19 limits signups per ip', function () {
    foreach (range(1, 10) as $i) {
        $this->signup(['payment_method_token' => null], "k{$i}")->assertStatus(422);
    }

    $this->signup(['payment_method_token' => null], 'k11')->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-22 isolates memberships by tenant with row level security', function () {
    $this->signup()->assertCreated();
    $this->signup(['slug' => 'segundo', 'owner_email' => 'b@exemplo.com'], 'segunda')->assertCreated();
    $tenantA = DB::connection('pgsql_admin')->table('tenants')->where('slug', 'tintaviva')->value('id');

    expect(DB::table('memberships')->count())->toBe(0);

    app(TenantDatabaseContext::class)->apply($tenantA);

    expect(DB::table('memberships')->count())->toBe(1);
    expect(DB::table('memberships')->value('tenant_id'))->toBe($tenantA);
});

it('does not leak whether the platform tables exist through errors when the payload is broken', function () {
    $this->postJson('http://app.test/api/v1/signup', ['business_name' => str_repeat('x', 5000)], ['Idempotency-Key' => 'k'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');
});
