<?php

use Illuminate\Support\Facades\DB;

it('CA-12 records the login flow without secrets', function () {
    $user = $this->platformUser();
    $this->login($user->email, 'senha-errada-123');
    $token = $this->accessTokenFor($user);
    $this->asToken($token, 'POST', 'auth/logout');

    $events = DB::connection('pgsql_admin')->table('platform_audit_logs')->orderBy('id')->get();

    expect($events->pluck('event')->all())->toContain('login.failed', 'login.password_ok', 'login.success', 'logout');

    $dump = $events->toJson();
    expect($dump)->not->toContain(self::PASSWORD)->not->toContain('senha-errada-123')->not->toContain((string) $user->totp_secret);
});

it('CA-12 records two factor activation', function () {
    $user = $this->platformUser(twoFactor: false);
    $challenge = $this->login($user->email)->json('challenge_token');
    $secret = $this->asToken($challenge, 'POST', '2fa/setup')->json('secret');
    $this->asToken($challenge, 'POST', '2fa/confirm', ['code' => $this->otp($secret)]);

    expect(DB::connection('pgsql_admin')->table('platform_audit_logs')->pluck('event')->all())->toContain('two_factor.enabled');
});
