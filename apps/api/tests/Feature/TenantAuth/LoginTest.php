<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('CA-01 logs in with email and password and returns a bearer token with the person and the role', function () {
    $this->workspace();

    $response = $this->loginAt('neg')->assertOk()->assertJson([
        'token_type' => 'Bearer', 'user' => ['email' => 'ana@exemplo.com', 'role' => 'owner'],
    ]);

    expect($response->json('access_token'))->toMatch('/^[0-9a-f\-]{36}\|[A-Za-z0-9]{40}$/');
    $expires = Carbon\Carbon::parse($response->json('expires_at'));
    expect($expires->between(now()->addHours(12)->subMinute(), now()->addHours(12)->addMinute()))->toBeTrue();
    expect($response->json())->not->toHaveKey('password');
});

it('CA-01 accepts the email in any case', function () {
    $this->workspace();

    $this->loginAt('neg', 'ANA@Exemplo.com')->assertOk();
});

it('CA-02 answers identically for unknown email, wrong password and a person who is not a member of this business', function () {
    $this->workspace('neg', 'ana@exemplo.com');
    $this->workspace('outro', 'beto@exemplo.com');

    $responses = [
        $this->loginAt('neg', 'ninguem@exemplo.com'),
        $this->loginAt('neg', 'ana@exemplo.com', 'senha-errada-123'),
        $this->loginAt('neg', 'beto@exemplo.com'),
    ];

    foreach ($responses as $response) {
        $response->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
    }
    expect(collect($responses)->map(fn ($r) => $r->json())->unique()->count())->toBe(1);
});

it('CA-02 logs the same person into each business they belong to', function () {
    $this->workspace('primeiro', 'ana@exemplo.com');
    $userId = DB::connection('pgsql_admin')->table('users')->value('id');
    $this->workspace('segundo', 'ana@exemplo.com', 'manager', 'active', $userId);

    $this->loginAt('primeiro')->assertOk()->assertJsonPath('user.role', 'owner');
    $this->loginAt('segundo')->assertOk()->assertJsonPath('user.role', 'manager');
});

it('CA-03 locks the account after five failures and releases it after fifteen minutes', function () {
    $this->workspace();

    foreach (range(1, 5) as $_) {
        $this->loginAt('neg', 'ana@exemplo.com', 'senha-errada-123')->assertUnauthorized();
    }

    $this->loginAt('neg')->assertStatus(423)->assertHeader('Content-Type', 'application/problem+json');

    $this->travel(16)->minutes();

    $this->loginAt('neg')->assertOk();
});

it('CA-03 locks an unknown email just like a real one', function () {
    $this->workspace();

    $statuses = collect(range(1, 6))->map(fn () => $this->loginAt('neg', 'fantasma@exemplo.com', 'senha-errada-123')->status());

    expect($statuses->all())->toBe([401, 401, 401, 401, 401, 423]);
});

it('CA-03 counts a failed attempt from a person without membership against the account', function () {
    $this->workspace('neg', 'ana@exemplo.com');
    $this->workspace('outro', 'beto@exemplo.com');

    foreach (range(1, 5) as $_) {
        $this->loginAt('neg', 'beto@exemplo.com')->assertUnauthorized();
    }

    $this->loginAt('outro', 'beto@exemplo.com')->assertStatus(423);
});

it('CA-19 limits login attempts per address', function () {
    $this->workspace();

    foreach (range(1, 10) as $i) {
        $this->loginAt('neg', "pessoa{$i}@exemplo.com")->assertUnauthorized();
    }

    $this->loginAt('neg', 'pessoa11@exemplo.com')->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-19 limits login attempts per business and email even when the address changes', function () {
    $this->workspace();

    foreach (range(1, 10) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$i}"])->loginAt('neg', 'ana@exemplo.com', 'senha-errada-123');
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])->loginAt('neg')->assertStatus(429);
});

it('validates the login payload', function () {
    $this->workspace();

    $this->postJson($this->authUrl('neg', 'auth/login'), [])->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
    $this->postJson($this->authUrl('neg', 'auth/login'), ['email' => 'nao-e-email', 'password' => 'x'])->assertStatus(422);
    $this->postJson($this->authUrl('neg', 'auth/login'), ['email' => ['a@b.co'], 'password' => 'x'])->assertStatus(422);
});

it('CA-09 lets someone from a suspended business log in, read, and still use the authentication routes', function () {
    $this->workspace('neg', 'ana@exemplo.com', 'owner', 'suspended');

    $token = $this->tokenFor();
    $this->asUser($token, 'GET', 'neg', 'auth/me')->assertOk()->assertJsonPath('can_write', false);
    $this->asUser($token, 'POST', 'neg', 'auth/logout')->assertNoContent();
});

it('CA-20 is not available on the platform host', function () {
    $this->workspace();

    $this->postJson('http://app.test/api/v1/auth/login', ['email' => 'ana@exemplo.com', 'password' => self::PASSWORD])->assertNotFound();
});

it('does not log in a user of an unknown business address', function () {
    $this->workspace();

    $this->postJson($this->authUrl('fantasma', 'auth/login'), ['email' => 'ana@exemplo.com', 'password' => self::PASSWORD])->assertNotFound();
});

it('resets the failure counter after a successful login and records the last login', function () {
    $this->workspace();
    $this->loginAt('neg', 'ana@exemplo.com', 'senha-errada-123');
    $this->loginAt('neg')->assertOk();

    $user = $this->userRow();

    expect($user->failed_attempts)->toBe(0)->and($user->last_login_at)->not->toBeNull();
});

it('keeps the equal timing path: the password is always checked, even for a locked account', function () {
    $this->workspace();
    DB::connection('pgsql_admin')->table('users')->update(['locked_until' => now()->addMinutes(10)]);
    Hash::spy();
    Hash::shouldReceive('check')->once()->andReturn(false);

    $this->loginAt('neg')->assertStatus(423);
});
