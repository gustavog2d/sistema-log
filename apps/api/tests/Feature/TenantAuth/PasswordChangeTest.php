<?php

use Illuminate\Support\Facades\DB;

beforeEach(fn () => $this->workspace());

it('CA-12 changes the password, returns a fresh session and drops every other access', function () {
    $current = $this->tokenFor();
    $other = $this->tokenFor();

    $response = $this->asUser($current, 'POST', 'neg', 'auth/password/change', ['current_password' => self::PASSWORD, 'password' => 'outra-senha-bem-forte-1'])
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_at', 'user' => ['id', 'name', 'email', 'role']]);

    $this->asUser($current, 'GET', 'neg', 'auth/me')->assertUnauthorized();
    $this->asUser($other, 'GET', 'neg', 'auth/me')->assertUnauthorized();
    $this->asUser($response->json('access_token'), 'GET', 'neg', 'auth/me')->assertOk();

    $this->loginAt('neg', 'ana@exemplo.com', self::PASSWORD)->assertUnauthorized();
    $this->loginAt('neg', 'ana@exemplo.com', 'outra-senha-bem-forte-1')->assertOk();
});

it('CA-12 refuses a wrong current password without changing anything', function () {
    $token = $this->tokenFor();

    $this->asUser($token, 'POST', 'neg', 'auth/password/change', ['current_password' => 'senha-errada-123', 'password' => 'outra-senha-bem-forte-1'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');

    $this->asUser($token, 'GET', 'neg', 'auth/me')->assertOk();
    $this->loginAt('neg')->assertOk();
});

it('CA-12 refuses to reuse the same password', function () {
    $this->asUser($this->tokenFor(), 'POST', 'neg', 'auth/password/change', ['current_password' => self::PASSWORD, 'password' => self::PASSWORD])->assertStatus(422);
});

it('CA-12 counts wrong current passwords against the account lock', function () {
    $token = $this->tokenFor();

    foreach (range(1, 5) as $_) {
        $this->asUser($token, 'POST', 'neg', 'auth/password/change', ['current_password' => 'senha-errada-123', 'password' => 'outra-senha-bem-forte-1'])->assertStatus(422);
    }

    $this->asUser($token, 'POST', 'neg', 'auth/password/change', ['current_password' => self::PASSWORD, 'password' => 'outra-senha-bem-forte-1'])->assertStatus(423);
});

it('CA-13 refuses weak or too long new passwords and a missing current password', function (array $payload) {
    $this->asUser($this->tokenFor(), 'POST', 'neg', 'auth/password/change', $payload)->assertStatus(422);
})->with([
    'curta' => [['current_password' => 'uma-senha-forte-123', 'password' => 'curta']],
    'acima de 72 bytes' => [['current_password' => 'uma-senha-forte-123', 'password' => str_repeat('a', 73)]],
    'sem a senha atual' => [['password' => 'outra-senha-bem-forte-1']],
]);

it('requires authentication to change the password', function () {
    $this->postJson($this->authUrl('neg', 'auth/password/change'), ['current_password' => self::PASSWORD, 'password' => 'outra-senha-bem-forte-1'])->assertUnauthorized();
});

it('stores the new password hashed', function () {
    $this->asUser($this->tokenFor(), 'POST', 'neg', 'auth/password/change', ['current_password' => self::PASSWORD, 'password' => 'outra-senha-bem-forte-1'])->assertOk();

    $hash = DB::connection('pgsql_admin')->table('users')->value('password');

    expect($hash)->not->toContain('outra-senha')->and(password_verify('outra-senha-bem-forte-1', $hash))->toBeTrue();
});
