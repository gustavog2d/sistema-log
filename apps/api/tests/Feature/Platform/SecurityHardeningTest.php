<?php

use App\Models\PlatformUser;
use App\Platform\Auth\LoginGuard;
use App\Platform\Auth\TwoFactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('A1 keeps passwords, emails and codes out of the application log', function () {
    $logFile = storage_path('logs/laravel.log');
    @unlink($logFile);
    $user = $this->platformUser();
    $user->recovery_codes = [Hash::make('abcd-efgh-1234')];
    $user->save();

    $this->login($user->email, 'SenhaMuitoSecreta987');
    $this->login('desconhecido@exemplo.com', 'OutraSenhaSecreta654');
    $challenge = $this->login($user->email)->json('challenge_token');
    $this->asToken($challenge, 'POST', 'auth/2fa/verify', ['code' => 'wxyz-wxyz-9999']);

    $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    expect($log)->not->toContain('SenhaMuitoSecreta987')
        ->not->toContain('OutraSenhaSecreta654')
        ->not->toContain('desconhecido@exemplo.com')
        ->not->toContain('wxyz-wxyz-9999');
});

it('M1 locks an unknown email after 5 failures just like a real account', function () {
    $statuses = collect(range(1, 6))->map(fn () => $this->login('fantasma@exemplo.com', 'senha-errada-123')->status());

    expect($statuses->all())->toBe([401, 401, 401, 401, 401, 423]);
});

it('M1 checks the password hash even when the account is locked', function () {
    $user = $this->platformUser(attributes: ['locked_until' => now()->addMinutes(10)]);
    Hash::spy();
    Hash::shouldReceive('check')->once()->andReturn(false);

    $this->login($user->email)->assertStatus(423);
});

it('M2 does not accept the same totp code twice, even the first one', function () {
    $user = $this->platformUser(twoFactor: false);
    $challenge = $this->login($user->email)->json('challenge_token');
    $secret = $this->asToken($challenge, 'POST', '2fa/setup')->json('secret');
    $code = $this->otp($secret);
    $this->asToken($challenge, 'POST', '2fa/confirm', ['code' => $code])->assertOk();

    $again = $this->login($user->email)->json('challenge_token');

    $this->asToken($again, 'POST', 'auth/2fa/verify', ['code' => $code])->assertUnauthorized();
});

it('M2 stores a real timestamp as the last used totp step', function () {
    $user = $this->platformUser();
    $this->accessTokenFor($user);

    expect($user->fresh()->totp_last_timestamp)->toBeGreaterThan(1_000_000);
});

it('M3 rejects tokens of a user deactivated by any path', function () {
    $user = $this->platformUser();
    $access = $this->accessTokenFor($user);
    $challenge = $this->login($user->email)->json('challenge_token');

    PlatformUser::whereKey($user->id)->first()->update(['active' => false]);

    $this->asToken($access, 'GET', 'me')->assertUnauthorized();
    $this->asToken($challenge, 'POST', 'auth/2fa/verify', ['code' => '123456'])->assertUnauthorized();
});

it('M3 rejects an inactive user even if a token survives', function () {
    $user = $this->platformUser();
    $access = $this->accessTokenFor($user);

    // Atualização direta no banco, sem eventos do modelo: o token continua existindo.
    DB::connection('pgsql_admin')->table('platform_users')->where('id', $user->id)->update(['active' => false]);

    $this->asToken($access, 'GET', 'me')->assertUnauthorized();
});

it('M4 counts failures against the fresh row, not a stale copy', function () {
    $user = $this->platformUser();
    $first = PlatformUser::find($user->id);
    $second = PlatformUser::find($user->id);

    app(LoginGuard::class)->registerFailure($first, 'wrong_code');
    app(LoginGuard::class)->registerFailure($second, 'wrong_code');

    expect($user->fresh()->failed_attempts)->toBe(2);
});

it('M4 lets a recovery code be consumed only once even from stale copies', function () {
    $user = $this->platformUser();
    $user->recovery_codes = [Hash::make('abcd-efgh-1234')];
    $user->save();
    $first = PlatformUser::find($user->id);
    $second = PlatformUser::find($user->id);

    $results = [
        app(TwoFactor::class)->verify($first, 'abcd-efgh-1234'),
        app(TwoFactor::class)->verify($second, 'abcd-efgh-1234'),
    ];

    expect($results)->toBe([true, false]);
});

it('M4 lets a totp code be used only once even from stale copies', function () {
    $user = $this->platformUser();
    $code = $this->otp((string) $user->totp_secret);
    $first = PlatformUser::find($user->id);
    $second = PlatformUser::find($user->id);

    $results = [app(TwoFactor::class)->verify($first, $code), app(TwoFactor::class)->verify($second, $code)];

    expect($results)->toBe([true, false]);
});

it('B1 answers 404 before authentication on a tenant host', function () {
    $this->makeTenant('acme');

    $this->getJson('http://acme.app.test/api/v1/platform/me')->assertNotFound();
});

it('B7 sends no-store on platform responses', function () {
    $user = $this->platformUser(twoFactor: false);
    $challenge = $this->login($user->email)->json('challenge_token');

    $this->asToken($challenge, 'POST', '2fa/setup')->assertHeader('Cache-Control', 'no-store, private');
});

it('B7 answers 405 as problem details', function () {
    $this->getJson($this->platformUrl('auth/login'))
        ->assertStatus(405)
        ->assertHeader('Content-Type', 'application/problem+json');
});
