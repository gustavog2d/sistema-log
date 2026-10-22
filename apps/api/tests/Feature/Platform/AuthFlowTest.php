<?php

use Illuminate\Support\Facades\Hash;

it('CA-01 gives a setup challenge to a user without 2fa', function () {
    $user = $this->platformUser(twoFactor: false);

    $this->login($user->email)
        ->assertOk()
        ->assertJson(['next' => 'setup'])
        ->assertJsonStructure(['challenge_token', 'next', 'expires_at']);
});

it('CA-02 sets up and confirms 2fa, returning recovery codes and an access token', function () {
    $user = $this->platformUser(twoFactor: false);
    $challenge = $this->login($user->email)->json('challenge_token');

    $setup = $this->asToken($challenge, 'POST', '2fa/setup')->assertOk()->assertJsonStructure(['secret', 'otpauth_uri']);
    expect($setup->json('otpauth_uri'))->toStartWith('otpauth://totp/');

    $confirmed = $this->asToken($challenge, 'POST', '2fa/confirm', ['code' => $this->otp($setup->json('secret'))])
        ->assertOk()
        ->assertJsonStructure(['recovery_codes', 'access_token', 'token_type', 'expires_at']);

    expect($confirmed->json('recovery_codes'))->toHaveCount(8);
    $this->asToken($confirmed->json('access_token'), 'GET', 'me')->assertOk()->assertJson(['email' => $user->email]);
});

it('CA-02 rejects confirming 2fa with a wrong code', function () {
    $user = $this->platformUser(twoFactor: false);
    $challenge = $this->login($user->email)->json('challenge_token');
    $this->asToken($challenge, 'POST', '2fa/setup')->assertOk();

    $this->asToken($challenge, 'POST', '2fa/confirm', ['code' => '000000'])->assertUnauthorized();
});

it('CA-03 issues an access token after password and a valid code', function () {
    $user = $this->platformUser();
    $login = $this->login($user->email)->assertOk()->assertJson(['next' => 'verify']);

    $this->asToken($login->json('challenge_token'), 'POST', 'auth/2fa/verify', ['code' => $this->otp((string) $user->totp_secret)])
        ->assertOk()
        ->assertJson(['token_type' => 'Bearer'])
        ->assertJsonStructure(['access_token', 'expires_at']);
});

it('CA-04 does not let a challenge token reach protected routes', function () {
    $user = $this->platformUser();
    $challenge = $this->login($user->email)->json('challenge_token');

    $this->asToken($challenge, 'GET', 'me')->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-05 returns the profile for an access token', function () {
    $user = $this->platformUser();

    $this->asToken($this->accessTokenFor($user), 'GET', 'me')
        ->assertOk()
        ->assertExactJson(['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'super_admin']);
});

it('CA-05 requires authentication for protected routes', function () {
    $this->getJson($this->platformUrl('me'))->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-08 rejects a wrong 2fa code', function () {
    $user = $this->platformUser();
    $challenge = $this->login($user->email)->json('challenge_token');

    $this->asToken($challenge, 'POST', 'auth/2fa/verify', ['code' => '000000'])->assertUnauthorized();
});

it('CA-08 accepts a recovery code only once', function () {
    $user = $this->platformUser();
    $user->recovery_codes = [Hash::make('abcd-efgh-1234')];
    $user->save();

    $first = $this->login($user->email)->json('challenge_token');
    $this->asToken($first, 'POST', 'auth/2fa/verify', ['code' => 'abcd-efgh-1234'])->assertOk()->assertJsonStructure(['access_token']);

    $second = $this->login($user->email)->json('challenge_token');
    $this->asToken($second, 'POST', 'auth/2fa/verify', ['code' => 'abcd-efgh-1234'])->assertUnauthorized();
});

it('CA-10 revokes the token on logout', function () {
    $token = $this->accessTokenFor($this->platformUser());

    $this->asToken($token, 'POST', 'auth/logout')->assertNoContent();
    $this->asToken($token, 'GET', 'me')->assertUnauthorized();
});

it('RN-10 revokes every token when the user is deactivated', function () {
    $user = $this->platformUser();
    $token = $this->accessTokenFor($user);

    $user->deactivate();

    $this->asToken($token, 'GET', 'me')->assertUnauthorized();
});

it('RN-05 does not let a challenge token be used to verify twice', function () {
    $user = $this->platformUser();
    $challenge = $this->login($user->email)->json('challenge_token');
    $code = $this->otp((string) $user->totp_secret);

    $this->asToken($challenge, 'POST', 'auth/2fa/verify', ['code' => $code])->assertOk();
    $this->asToken($challenge, 'POST', 'auth/2fa/verify', ['code' => $code])->assertUnauthorized();
});

it('RN-04 refuses the setup step when 2fa is already active', function () {
    $user = $this->platformUser();
    $challenge = $this->login($user->email)->json('challenge_token');

    $this->asToken($challenge, 'POST', '2fa/setup')->assertForbidden();
});

it('CA-05 answers 401 problem details even when the client sends no Accept header', function () {
    $this->get($this->platformUrl('me'))
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-04 answers 403 problem details for a challenge token without leaking internals', function () {
    $user = $this->platformUser();
    $challenge = $this->login($user->email)->json('challenge_token');
    $this->app['auth']->forgetGuards();

    $response = $this->withToken($challenge)->get($this->platformUrl('me'));

    $response->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
    expect($response->json('detail'))->toBe('Acesso negado.');
});
