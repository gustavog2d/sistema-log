<?php

use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

it('CA-01 sends one welcome to the owner after the signup', function () {
    $this->seedCatalog();

    $this->signup()->assertCreated();

    expect(queuedCount('welcome', 'ana@exemplo.com'))->toBe(1)->and(allQueued())->toBe(1);
});

it('CA-01 does not send a second welcome when the signup is repeated with the same key', function () {
    $this->seedCatalog();

    $this->signup()->assertCreated();
    $this->signup()->assertCreated();

    expect(queuedCount('welcome'))->toBe(1);
});

it('CA-08 warns the person when the password is changed', function () {
    $this->workspace('neg', 'ana@exemplo.com');
    $token = $this->tokenFor('neg');

    $this->asUser($token, 'POST', 'neg', 'auth/password/change', ['current_password' => self::PASSWORD, 'password' => 'outra-senha-longa-456'])->assertOk();

    expect(queuedCount('password_changed', 'ana@exemplo.com'))->toBe(1);
});

it('CA-08 warns the person when the password is reset by the link', function () {
    $this->workspace('neg', 'ana@exemplo.com');
    $this->postJson($this->authUrl('neg', 'auth/password/forgot'), ['email' => 'ana@exemplo.com'])->assertStatus(202);
    $token = Mail::sent(PasswordResetMail::class)->last()->token;

    $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $token, 'password' => 'outra-senha-longa-456'])->assertNoContent();

    expect(queuedCount('password_changed', 'ana@exemplo.com'))->toBe(1);
});

it('CA-09 warns the person when 2fa is turned off', function () {
    $this->workspace('neg', 'ana@exemplo.com');
    $this->enableTwoFactor('neg');
    $session = $this->tokenFor('neg') ?: '';
    $this->travel(31)->seconds();
    $challenge = $this->loginAt('neg')->json('challenge_token');
    $setup = DB::connection('pgsql_admin')->table('users')->first();
    $secret = Crypt::decryptString($setup->totp_secret);
    $token = $this->asUser($challenge, 'POST', 'neg', 'auth/2fa/verify', ['code' => $this->totp($secret)])->assertOk()->json('access_token');

    $this->asUser($token, 'POST', 'neg', 'auth/2fa/disable', ['current_password' => self::PASSWORD])->assertNoContent();

    expect(queuedCount('two_factor_disabled', 'ana@exemplo.com'))->toBe(1);
});
