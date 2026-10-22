<?php

use App\Billing\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(fn () => $this->seedCatalog());

it('CA-01 starts a 7 day trial and returns the business, the subscription and the card', function () {
    $response = $this->signup()->assertCreated();

    $response->assertJson([
        'tenant' => ['slug' => 'tintaviva', 'name' => 'Estúdio Tinta Viva', 'niche' => 'tatuagem'],
        'subscription' => ['status' => 'trialing', 'plan' => 'profissional', 'cycle' => 'monthly', 'price_cents' => 5000, 'currency' => 'BRL'],
        'payment_method' => ['type' => 'card'],
    ]);

    $trialEnds = Carbon\Carbon::parse($response->json('subscription.trial_ends_at'));
    expect($trialEnds->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue();
    expect($response->json('subscription.first_charge_at'))->toBe($response->json('subscription.trial_ends_at'));
    expect($response->json('payment_method.last4'))->toMatch('/^\d{4}$/');
});

it('CA-02 stores the business, the owner, the membership, the card and the subscription linked together', function () {
    $this->signup()->assertCreated();
    $db = DB::connection('pgsql_admin');

    $tenant = $db->table('tenants')->first();
    $user = $db->table('users')->first();
    $membership = $db->table('memberships')->first();
    $subscription = $db->table('subscriptions')->first();
    $method = $db->table('payment_methods')->first();

    expect($tenant->niche)->toBe('tatuagem')->and($tenant->slug)->toBe('tintaviva');
    expect($membership->tenant_id)->toBe($tenant->id)->and($membership->user_id)->toBe($user->id)->and($membership->role)->toBe('owner');
    expect($user->email)->toBe('ana@exemplo.com')->and($user->name)->toBe('Ana Souza');
    expect($subscription->tenant_id)->toBe($tenant->id)->and($subscription->payment_method_id)->toBe($method->id)->and($subscription->status)->toBe('trialing');
    expect($method->tenant_id)->toBe($tenant->id);
});

it('CA-03 refuses a signup without a valid payment method and creates nothing', function (array $override) {
    $this->signup($override)->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');

    $this->nothingWasCreated();
})->with([
    'sem token' => [['payment_method_token' => null]],
    'token recusado' => [['payment_method_token' => 'tok_declined']],
    'token desconhecido' => [['payment_method_token' => 'qualquer-coisa']],
]);

it('CA-04 does not charge anything when the trial starts', function () {
    $this->signup()->assertCreated();

    expect(app(PaymentGateway::class)->charges())->toBe([]);
});

it('CA-05 refuses unknown, draft or archived plans and unknown cycles', function (array $override, ?string $statusToSet) {
    if ($statusToSet !== null) {
        DB::connection('pgsql_admin')->table('plans')->where('key', 'estudio')->update(
            $statusToSet === 'draft'
                ? ['status' => 'draft', 'monthly_price_cents' => null, 'yearly_discount_percent' => null]
                : ['status' => 'archived'],
        );
    }

    $this->signup($override)->assertStatus(422);
    $this->nothingWasCreated();
})->with([
    'plano inexistente' => [['plan' => 'fantasma'], null],
    'plano em rascunho' => [['plan' => 'estudio'], 'draft'],
    'plano arquivado' => [['plan' => 'estudio'], 'archived'],
    'ciclo inválido' => [['cycle' => 'semanal'], null],
]);

it('CA-06 charges the yearly catalog price for the yearly cycle', function () {
    $this->signup(['plan' => 'essencial', 'cycle' => 'yearly'])->assertCreated()->assertJson(['subscription' => ['price_cents' => 16200, 'cycle' => 'yearly']]);
});

it('CA-07 keeps the price the customer signed up with when the plan changes later', function () {
    $this->signup()->assertCreated();

    DB::connection('pgsql_admin')->table('plans')->where('key', 'profissional')->update(['monthly_price_cents' => 9999]);

    expect(DB::connection('pgsql_admin')->table('subscriptions')->value('price_cents'))->toBe(5000);
});

it('CA-09 refuses a taken or reserved address', function (string $slug) {
    $this->signup()->assertCreated();

    $this->signup(['slug' => $slug, 'owner_email' => 'outra@exemplo.com'], 'outra-chave')->assertStatus(422);
    expect($this->countAll('tenants'))->toBe(1);
})->with(['tintaviva', 'admin', 'www', 'api']);

it('CA-10 refuses a niche outside the list', function () {
    $this->signup(['niche' => 'padaria'])->assertStatus(422);
    $this->nothingWasCreated();
});

it('CA-10 accepts every launch niche', function (string $niche) {
    $this->signup(['niche' => $niche, 'slug' => 'n'.substr(md5($niche), 0, 8), 'owner_email' => "{$niche}@exemplo.com"], "k-{$niche}")->assertCreated();
})->with(['tatuagem', 'manicure', 'barbearia', 'estetica_automotiva']);

it('CA-11 refuses duplicate email, short password and missing terms', function (array $override) {
    $this->signup()->assertCreated();

    $this->signup($override + ['slug' => 'outroendereco'], 'outra-chave')->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
    expect($this->countAll('tenants'))->toBe(1);
})->with([
    'e-mail repetido' => [['owner_email' => 'ANA@exemplo.com']],
    'senha curta' => [['password' => 'curta', 'owner_email' => 'b@exemplo.com']],
    'termos não aceitos' => [['accept_terms' => false, 'owner_email' => 'c@exemplo.com']],
    'termos ausentes' => [['accept_terms' => null, 'owner_email' => 'd@exemplo.com']],
]);

it('CA-12 records the terms acceptance and stores the password hashed with a lowercase email', function () {
    $this->signup(['owner_email' => 'Ana@Exemplo.com'])->assertCreated();
    $user = DB::connection('pgsql_admin')->table('users')->first();

    expect($user->email)->toBe('ana@exemplo.com');
    expect($user->terms_accepted_at)->not->toBeNull()->and($user->terms_version)->toBe(config('billing.terms_version'));
    expect($user->password)->not->toBe('uma-senha-forte-123')->and(Hash::check('uma-senha-forte-123', $user->password))->toBeTrue();
});

it('CA-18 accepts a card that will fail on the first charge', function () {
    $this->signup(['payment_method_token' => 'tok_fail_on_charge'])->assertCreated();
});

it('CA-20 audits the signup without secrets', function () {
    $this->signup()->assertCreated();

    $event = DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'signup.completed')->first();

    expect($event)->not->toBeNull();
    expect($event->metadata)->not->toContain('uma-senha-forte-123')->not->toContain('tok_ok')->toContain('profissional');
});

it('CA-21 is not available on a tenant host', function () {
    $this->makeTenant('acme');

    $this->postJson('http://acme.app.test/api/v1/signup', $this->signupPayload(), ['Idempotency-Key' => 'x'])->assertNotFound();
});

it('also answers on the admin host', function () {
    $this->postJson('http://admin.app.test/api/v1/signup', $this->signupPayload(), ['Idempotency-Key' => 'x'])->assertCreated();
});
