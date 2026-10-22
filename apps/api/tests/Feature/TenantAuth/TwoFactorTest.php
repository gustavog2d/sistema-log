<?php

beforeEach(fn () => $this->workspace());

it('CA-14 turns on 2fa and then asks for the code at login', function () {
    $setup = $this->enableTwoFactor();

    expect($setup['recovery_codes'])->toHaveCount(8);
    expect($this->userRow()->totp_confirmed_at)->not->toBeNull();

    $login = $this->loginAt('neg')->assertOk()->assertJsonStructure(['challenge_token', 'next', 'expires_at'])->assertJson(['next' => 'verify']);
    expect($login->json())->not->toHaveKey('access_token');
});

it('CA-14 trades the challenge for an access token with a valid code', function () {
    $setup = $this->enableTwoFactor();
    $this->travel(31)->seconds();
    $challenge = $this->loginAt('neg')->json('challenge_token');

    $session = $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => $this->totp($setup['secret'])])
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_at', 'user']);

    $this->asUser($session->json('access_token'), 'GET', 'neg', 'auth/me')->assertOk()->assertJsonPath('two_factor_enabled', true);
});

it('CA-14 never accepts the same totp code twice', function () {
    $setup = $this->enableTwoFactor();
    $code = $this->totp($setup['secret']);
    // O código usado na confirmação ainda está na janela: já conta como usado.
    $first = $this->loginAt('neg')->json('challenge_token');

    $this->asUser($first, 'POST', 'neg', 'auth/2fa/verify', ['code' => $code])->assertUnauthorized();
});

it('CA-14 refuses a wrong code and a challenge reused after success', function () {
    $setup = $this->enableTwoFactor();
    $this->travel(31)->seconds();
    $challenge = $this->loginAt('neg')->json('challenge_token');

    $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => '000000'])->assertUnauthorized();
    $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => $this->totp($setup['secret'])])->assertOk();
    $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => $this->totp($setup['secret'])])->assertUnauthorized();
});

it('CA-14 locks the account after five wrong codes', function () {
    $this->enableTwoFactor();

    foreach (range(1, 5) as $_) {
        $challenge = $this->loginAt('neg')->json('challenge_token');
        $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => '000000'])->assertUnauthorized();
    }

    $this->loginAt('neg')->assertStatus(423);
});

it('CA-14 accepts a recovery code only once', function () {
    $setup = $this->enableTwoFactor();
    $code = $setup['recovery_codes'][0];

    $first = $this->loginAt('neg')->json('challenge_token');
    $this->asUser($first, 'POST', 'neg', 'auth/2fa/verify', ['code' => $code])->assertOk();

    $second = $this->loginAt('neg')->json('challenge_token');
    $this->asUser($second, 'POST', 'neg', 'auth/2fa/verify', ['code' => $code])->assertUnauthorized();
});

it('CA-14 refuses a challenge that expired', function () {
    $setup = $this->enableTwoFactor();
    $this->travel(31)->seconds();
    $challenge = $this->loginAt('neg')->json('challenge_token');

    $this->travel(6)->minutes();

    $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => $this->totp($setup['secret'])])->assertUnauthorized();
});

it('does not let an access token verify a code or a challenge token be used for management', function () {
    $setup = $this->enableTwoFactor();
    $access = $this->tokenFor('neg');
    $this->travel(31)->seconds();
    $challenge = $this->loginAt('neg')->json('challenge_token');

    $this->asUser($setup['token'], 'POST', 'neg', 'auth/2fa/verify', ['code' => '123456'])->assertForbidden();
    $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/disable', ['current_password' => self::PASSWORD])->assertForbidden();
});

it('refuses to set up 2fa again while it is active and rejects a wrong confirmation code', function () {
    $this->enableTwoFactor();
    $this->asUser($this->loginTokenAfterTwoFactor(), 'POST', 'neg', 'auth/2fa/setup')->assertForbidden();
});

it('CA-14 rejects a wrong code while confirming and keeps 2fa off', function () {
    $token = $this->tokenFor();
    $this->asUser($token, 'POST', 'neg', 'auth/2fa/setup')->assertOk();

    $this->asUser($token, 'POST', 'neg', 'auth/2fa/confirm', ['code' => '000000'])->assertStatus(422);

    expect($this->userRow()->totp_confirmed_at)->toBeNull();
    $this->loginAt('neg')->assertOk()->assertJsonStructure(['access_token']);
});

it('disables 2fa with the current password and then logs in without a code', function () {
    $setup = $this->enableTwoFactor();
    $this->travel(31)->seconds();
    $challenge = $this->loginAt('neg')->json('challenge_token');
    $session = $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => $this->totp($setup['secret'])])->assertOk()->json('access_token');

    $this->asUser($session, 'POST', 'neg', 'auth/2fa/disable', ['current_password' => 'senha-errada-123'])->assertStatus(422);
    $this->asUser($session, 'POST', 'neg', 'auth/2fa/disable', ['current_password' => self::PASSWORD])->assertNoContent();

    $this->loginAt('neg')->assertOk()->assertJsonStructure(['access_token']);
    expect($this->userRow()->totp_secret)->toBeNull();
});

it('CA-15 only lets an owner without 2fa set it up when the requirement is on', function () {
    config(['auth_tenant.owner_two_factor_required' => true]);

    $login = $this->loginAt('neg')->assertOk()->assertJson(['next' => 'setup']);
    expect($login->json())->not->toHaveKey('access_token');
    $setupToken = $login->json('challenge_token');

    $this->asUser($setupToken, 'GET', 'neg', 'auth/me')->assertForbidden();
    $secret = $this->asUser($setupToken, 'POST', 'neg', 'auth/2fa/setup')->assertOk()->json('secret');
    $this->asUser($setupToken, 'POST', 'neg', 'auth/2fa/confirm', ['code' => $this->totp($secret)])->assertOk()->assertJsonCount(8, 'recovery_codes');

    $this->loginAt('neg')->assertOk()->assertJson(['next' => 'verify']);
});

it('CA-15 does not force 2fa on people who are not owners', function () {
    config(['auth_tenant.owner_two_factor_required' => true]);
    $this->workspace('time', 'prof@exemplo.com', 'professional');

    $this->loginAt('time', 'prof@exemplo.com')->assertOk()->assertJsonStructure(['access_token']);
});

it('keeps the totp secret and recovery codes encrypted and hides them from every response', function () {
    $setup = $this->enableTwoFactor();
    $row = $this->userRow();

    expect($row->totp_secret)->not->toContain($setup['secret']);
    expect($row->recovery_codes)->not->toContain($setup['recovery_codes'][0]);

    $me = $this->asUser($this->loginTokenAfterTwoFactor(), 'GET', 'neg', 'auth/me')->getContent();
    expect($me)->not->toContain($setup['secret'])->not->toContain('totp_secret')->not->toContain('recovery');
});

it('lets the verification of a challenge fail without leaking which part was wrong', function () {
    $this->enableTwoFactor();
    $this->travel(31)->seconds();
    $challenge = $this->loginAt('neg')->json('challenge_token');

    $wrongCode = $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => '000000']);
    $malformed = $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => 'abc']);

    expect($wrongCode->json())->toBe($malformed->json());
});

it('validates the code field', function () {
    $this->enableTwoFactor();
    $this->travel(31)->seconds();
    $challenge = $this->loginAt('neg')->json('challenge_token');

    $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', [])->assertStatus(422);
    $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => str_repeat('1', 65)])->assertStatus(422);
});
