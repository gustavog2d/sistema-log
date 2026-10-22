<?php

use App\Tenancy\TenantDatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function sqlState(callable $query): ?string
{
    try {
        $query();

        return null;
    } catch (QueryException $e) {
        return (string) $e->getCode();
    }
}

it('CA-16 stores the password hashed and never returns secrets in login or me', function () {
    $this->workspace();
    $login = $this->loginAt('neg')->assertOk();
    $me = $this->asUser($login->json('access_token'), 'GET', 'neg', 'auth/me');

    $stored = DB::connection('pgsql_admin')->table('users')->value('password');
    expect($stored)->toStartWith('$2y$')->and($stored)->not->toContain(self::PASSWORD);

    foreach ([$login->getContent(), $me->getContent()] as $body) {
        expect($body)->not->toContain('password')->not->toContain('token_hash')->not->toContain('totp')->not->toContain($stored);
    }
});

it('CA-17 does not let the api role read the password or write tokens and reset tokens', function () {
    $this->workspace();
    $ws = DB::connection('pgsql_admin')->table('tenants')->first();

    expect(sqlState(fn () => DB::table('users')->select('password')->get()))->toBe('42501');
    expect(sqlState(fn () => DB::table('users')->select('totp_secret')->get()))->toBe('42501');
    expect(sqlState(fn () => DB::table('password_reset_tokens')->count()))->toBe('42501');

    app(TenantDatabaseContext::class)->apply($ws->id);
    expect(sqlState(fn () => DB::table('user_access_tokens')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $ws->id, 'user_id' => (string) Str::uuid(), 'kind' => 'access',
        'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addHour(), 'created_at' => now(),
    ])))->toBe('42501');
});

it('CA-17 keeps the auth role away from billing, platform and invoice data', function () {
    $this->workspace();

    foreach (['invoices', 'subscriptions', 'payment_methods', 'platform_users', 'platform_audit_logs', 'plans', 'idempotency_keys', 'personal_access_tokens'] as $table) {
        expect(sqlState(fn () => DB::connection('pgsql_auth')->table($table)->count()))->toBe('42501', "{$table} deveria ser inacessível ao papel de autenticação");
    }
});

it('CA-17 keeps the platform and signup roles away from passwords and tokens they do not need', function () {
    $this->workspace();

    expect(sqlState(fn () => DB::connection('pgsql_platform')->table('user_access_tokens')->count()))->toBe('42501');
    expect(sqlState(fn () => DB::connection('pgsql_platform')->table('password_reset_tokens')->count()))->toBe('42501');
});

it('CA-18 shows the api role only the tokens of the business in context', function () {
    $a = $this->workspace('negocioa', 'ana@exemplo.com');
    $this->workspace('negociob', 'beto@exemplo.com');
    $this->tokenFor('negocioa', 'ana@exemplo.com');
    $this->tokenFor('negociob', 'beto@exemplo.com');

    expect(DB::table('user_access_tokens')->count())->toBe(0);

    app(TenantDatabaseContext::class)->apply($a->tenant_id);

    expect(DB::table('user_access_tokens')->count())->toBe(1);
    expect(DB::table('user_access_tokens')->value('tenant_id'))->toBe($a->tenant_id);
});

it('CA-18 has row level security forced on the token table', function () {
    $row = DB::connection('pgsql_admin')->selectOne("select relrowsecurity, relforcerowsecurity from pg_class where relname = 'user_access_tokens'");

    expect($row->relrowsecurity)->toBeTrue()->and($row->relforcerowsecurity)->toBeTrue();
});

it('CA-18 gives the auth role no memberships without a business in context', function () {
    $this->workspace();

    expect(DB::connection('pgsql_auth')->table('memberships')->count())->toBe(0);
});

it('does not let the auth role write a token for a business other than the one it informed', function () {
    $a = $this->workspace('negocioa', 'ana@exemplo.com');
    $b = $this->workspace('negociob', 'beto@exemplo.com');

    $attempt = fn () => DB::connection('pgsql_auth')->transaction(function ($db) use ($a, $b) {
        $db->statement("select set_config('app.tenant_id', ?, true)", [$a->tenant_id]);
        $db->table('user_access_tokens')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $b->tenant_id, 'user_id' => $b->user_id, 'kind' => 'access',
            'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addHour(), 'created_at' => now(),
        ]);
    });

    expect(sqlState($attempt))->toBe('42501');
    expect(DB::connection('pgsql_admin')->table('user_access_tokens')->count())->toBe(0);
});

it('keeps the new user columns out of the api role', function () {
    $this->workspace();

    foreach (['failed_attempts', 'locked_until', 'recovery_codes', 'last_login_at'] as $column) {
        expect(sqlState(fn () => DB::table('users')->select($column)->get()))->toBe('42501', "{$column} deveria ser inacessível à API");
    }
});

it('CA-03 does not let wrong passwords in another business lock the account of someone who is not a member there', function () {
    $this->workspace('negocioa', 'ana@exemplo.com');
    $this->workspace('negociob', 'beto@exemplo.com');

    foreach (range(1, 6) as $ignored) {
        $this->loginAt('negociob', 'ana@exemplo.com', 'senha-errada-123');
    }

    $this->loginAt('negocioa', 'ana@exemplo.com')->assertOk()->assertJsonStructure(['access_token']);
});
