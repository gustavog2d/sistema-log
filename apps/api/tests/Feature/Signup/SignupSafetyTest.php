<?php

use App\Models\PlatformUser;
use App\Platform\Auth\PlatformAudit;
use App\Tenancy\TenantDatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(fn () => $this->seedCatalog());

it('CA-13 replays the same response for the same key and body, creating one business only', function () {
    $first = $this->signup(key: 'chave-repetida')->assertCreated();
    $second = $this->signup(key: 'chave-repetida')->assertCreated();

    expect($second->json())->toBe($first->json());
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

it('CA-16 does not let the api role write platform billing tables', function (string $table, array $row) {
    expect(fn () => DB::table($table)->insert($row + ['id' => (string) Str::uuid()]))->toThrow(QueryException::class);
})->with([
    'tenants' => ['tenants', ['name' => 'x', 'slug' => 'xyz', 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01']],
    'subscriptions' => ['subscriptions', ['status' => 'trialing']],
    'payment_methods' => ['payment_methods', ['brand' => 'visa']],
    'users' => ['users', ['name' => 'x', 'email' => 'x@y.co', 'password' => 'x']],
]);

it('CA-17 rolls everything back when a step after creating the business fails', function () {
    $this->app->bind(PlatformAudit::class, fn () => new class(request()) extends PlatformAudit
    {
        public function record(string $event, ?PlatformUser $user = null, array $metadata = []): void
        {
            throw new RuntimeException('falha no meio do cadastro');
        }
    });

    $this->signup()->assertStatus(500);

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
