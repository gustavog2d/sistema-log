<?php

use App\Notifications\TransactionalMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();
});

function impersonate($test, string $token, object $ws, array $body = ['reason' => 'Ajudar com o cadastro da agenda'])
{
    return adm($test, $token, 'POST', "tenants/{$ws->id}/impersonate", $body);
}

it('CA-09 gives support a short access as the owner, audited and warned to the owner', function () {
    $ws = adminTenant($this);

    $response = impersonate($this, $this->supportToken(), $ws)->assertOk()->assertJsonStructure(['access_token', 'token_type', 'expires_at', 'url']);

    $row = DB::connection('pgsql_admin')->table('user_access_tokens')->first();
    expect($row->user_id)->toBe($ws->owner_id)->and($row->kind)->toBe('access')->and($row->impersonated_by)->not->toBeNull()
        ->and(Carbon::parse($row->expires_at)->between(now()->addMinutes(29), now()->addMinutes(31)))->toBeTrue()
        ->and($response->json('url'))->toContain('neg.');

    $audit = DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'tenant.impersonated')->first();
    expect($audit->platform_user_id)->toBe($row->impersonated_by)->and($audit->metadata)->toContain('Ajudar com o cadastro da agenda')->toContain($ws->id);

    Mail::assertQueued(TransactionalMail::class, fn ($m) => $m->kind === 'support_access' && $m->hasTo('dona@exemplo.com'));
    expect(json_encode(Mail::queued(TransactionalMail::class)->first()->data))->not->toContain('Ajudar');
});

it('CA-09 requires a reason', function (array $body) {
    $ws = adminTenant($this);

    impersonate($this, $this->supportToken(), $ws, $body)->assertUnprocessable();
    expect(DB::connection('pgsql_admin')->table('user_access_tokens')->count())->toBe(0);
})->with([[[]], [['reason' => 'curto']], [['reason' => str_repeat('a', 201)]]]);

it('CA-09 works for the super admin and answers 404 for an unknown business and 409 without an active owner', function () {
    $ws = adminTenant($this);
    impersonate($this, $this->adminToken(), $ws)->assertOk();
    impersonate($this, $this->adminToken(), (object) ['id' => (string) Str::uuid()])->assertNotFound();

    DB::connection('pgsql_admin')->table('memberships')->update(['status' => 'inactive']);
    impersonate($this, $this->adminToken(), $ws)->assertStatus(409);
});

it('CA-10 uses the owner role but cannot touch credentials or billing, and me says it is a support access', function () {
    $ws = adminTenant($this);
    $token = impersonate($this, $this->supportToken(), $ws)->json('access_token');

    asTenant($this, $token, 'GET', 'neg', 'auth/me')->assertOk()->assertJsonPath('impersonating', true)->assertJsonPath('user.role', 'owner');
    asTenant($this, $token, 'GET', 'neg', 'users')->assertOk();

    foreach ([
        ['POST', 'auth/password/change', ['current_password' => 'x', 'password' => 'uma-outra-senha-123']],
        ['POST', 'auth/logout-all', []],
        ['POST', 'auth/2fa/setup', []],
        ['POST', 'auth/2fa/disable', ['current_password' => 'x']],
        ['POST', 'subscription/cancel', []],
        ['POST', 'subscription/reactivate', []],
        ['GET', 'subscription/invoices', []],
        ['PUT', 'subscription/payment-method', ['payment_method_token' => 'tok_ok']],
    ] as [$method, $path, $body]) {
        asTenant($this, $token, $method, 'neg', $path, $body)->assertForbidden()->assertJsonPath('code', 'impersonation_forbidden');
    }
});

it('CA-10 does not mark a normal access as a support access', function () {
    $ws = adminTenant($this);
    $id = (string) Str::uuid();
    $secret = Str::random(40);
    DB::connection('pgsql_admin')->table('user_access_tokens')->insert(['id' => $id, 'tenant_id' => $ws->id, 'user_id' => $ws->owner_id, 'kind' => 'access', 'token_hash' => hash('sha256', $secret), 'expires_at' => now()->addHour(), 'created_at' => now()]);

    asTenant($this, "{$id}|{$secret}", 'GET', 'neg', 'auth/me')->assertOk()->assertJsonPath('impersonating', false);
});

it('CA-11 stops working when the owner access falls', function () {
    $ws = adminTenant($this);
    $token = impersonate($this, $this->supportToken(), $ws)->json('access_token');
    asTenant($this, $token, 'GET', 'neg', 'auth/me')->assertOk();

    DB::connection('pgsql_admin')->table('memberships')->where('user_id', $ws->owner_id)->update(['status' => 'inactive']);

    asTenant($this, $token, 'GET', 'neg', 'auth/me')->assertUnauthorized();
});

it('CA-11 stops working when the owner password changes', function () {
    $ws = adminTenant($this);
    $token = impersonate($this, $this->supportToken(), $ws)->json('access_token');

    DB::connection('pgsql_admin')->table('users')->where('id', $ws->owner_id)->update(['tokens_valid_after' => now()->addSecond()]);

    asTenant($this, $token, 'GET', 'neg', 'auth/me')->assertUnauthorized();
});

it('does not let the token of one business open another', function () {
    $a = adminTenant($this, 'negocioa', 'active', [], 'a@exemplo.com');
    adminTenant($this, 'negociob', 'active', [], 'b@exemplo.com');
    $token = impersonate($this, $this->supportToken(), $a)->json('access_token');

    asTenant($this, $token, 'GET', 'negociob', 'auth/me')->assertUnauthorized();
});

it('CA-12 asks for a login and limits the attempts', function () {
    $ws = adminTenant($this);
    $this->postJson("http://app.test/api/v1/platform/tenants/{$ws->id}/impersonate", ['reason' => 'Ajudar com o cadastro'])->assertUnauthorized();

    $support = $this->supportToken();
    $last = null;
    foreach (range(1, 15) as $i) {
        $last = impersonate($this, $support, $ws)->status();
    }

    expect($last)->toBe(429);
});
