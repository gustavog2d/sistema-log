<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

function accept($test, string $email, string $token, string $password = 'uma-senha-longa-123', string $slug = 'neg')
{
    return $test->postJson("http://{$slug}.app.test/api/v1/auth/invitations/accept", ['email' => $email, 'token' => $token, 'password' => $password, 'accept_terms' => true]);
}

function invited($test, string $email = 'nova@exemplo.com', string $role = 'professional', string $slug = 'neg'): string
{
    [$token] = teamOf($test, $slug);
    team($test, $token, 'POST', $slug, 'users/invitations', ['name' => 'Nova Pessoa', 'email' => $email, 'role' => $role])->assertCreated();

    return inviteToken($email);
}

it('CA-06 creates the account, links the person with the invited role, logs in and consumes the token', function () {
    $plain = invited($this);

    $response = accept($this, 'nova@exemplo.com', $plain)->assertOk()->assertJsonStructure(['access_token', 'user']);

    expect($response->json('user.role'))->toBe('professional')
        ->and(membershipOf('nova@exemplo.com')->status)->toBe('active')
        ->and(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(0);

    team($this, $response->json('access_token'), 'GET', 'neg', 'auth/me')->assertOk();
    $this->loginAt('neg', 'nova@exemplo.com', 'uma-senha-longa-123')->assertOk();
    accept($this, 'nova@exemplo.com', $plain)->assertUnprocessable();
});

it('CA-06 enforces the password policy', function () {
    $plain = invited($this);

    accept($this, 'nova@exemplo.com', $plain, 'curta')->assertUnprocessable();
    accept($this, 'nova@exemplo.com', $plain, str_repeat('a', 73))->assertUnprocessable();
    expect(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(1);
});

it('CA-07 links a person who already has an account elsewhere only with their current password', function () {
    $this->workspace('outro', 'viajante@exemplo.com');
    $plain = invited($this, 'viajante@exemplo.com', 'manager');

    accept($this, 'viajante@exemplo.com', $plain, 'senha-errada-123456')->assertUnprocessable();
    expect(DB::connection('pgsql_admin')->table('memberships')->where('user_id', DB::connection('pgsql_admin')->table('users')->where('email', 'viajante@exemplo.com')->value('id'))->count())->toBe(1);

    accept($this, 'viajante@exemplo.com', $plain, self::PASSWORD)->assertOk()->assertJsonPath('user.role', 'manager');
    expect(DB::connection('pgsql_admin')->table('users')->where('email', 'viajante@exemplo.com')->count())->toBe(1);
});

it('CA-08 answers the same 422 for a wrong token, a wrong email, an expired one and a token from another business', function () {
    $plain = invited($this, 'nova@exemplo.com', 'reception', 'neg');
    teamOf($this, 'outro');

    $bodies = collect([
        accept($this, 'nova@exemplo.com', 'x'.$plain),
        accept($this, 'outra@exemplo.com', $plain),
        accept($this, 'nova@exemplo.com', $plain, slug: 'outro'),
    ])->map(fn ($r) => [$r->status(), $r->json('errors.token')]);

    DB::connection('pgsql_admin')->table('invitations')->update(['expires_at' => now()->subMinute()]);
    $expired = accept($this, 'nova@exemplo.com', $plain);

    expect($bodies->unique()->count())->toBe(1)->and($bodies->first()[0])->toBe(422)
        ->and([$expired->status(), $expired->json('errors.token')])->toBe($bodies->first());
});

it('CA-06 works even when the subscription is suspended', function () {
    $plain = invited($this);
    DB::connection('pgsql_admin')->table('subscriptions')->update(['status' => 'suspended']);

    accept($this, 'nova@exemplo.com', $plain)->assertOk();
});

it('CA-06 asks for the 2fa code when the invited person already has 2fa on', function () {
    $this->workspace('outro', 'viajante@exemplo.com');
    $this->enableTwoFactor('outro', 'viajante@exemplo.com');
    $plain = invited($this, 'viajante@exemplo.com', 'reception');

    accept($this, 'viajante@exemplo.com', $plain, self::PASSWORD)->assertOk()->assertJsonPath('next', 'verify');
});

it('rate limits the acceptance attempts', function () {
    invited($this);

    $last = null;
    foreach (range(1, 25) as $i) {
        $last = accept($this, 'nova@exemplo.com', 'tentativa'.$i)->status();
    }

    expect($last)->toBe(429);
});

it('CA-07 counts wrong passwords against the lock and does not consume the invitation (423)', function () {
    $this->workspace('outro', 'viajante@exemplo.com');
    $plain = invited($this, 'viajante@exemplo.com');

    foreach (range(1, 5) as $i) {
        accept($this, 'viajante@exemplo.com', $plain, 'senha-errada-12345')->assertUnprocessable();
    }

    accept($this, 'viajante@exemplo.com', $plain, self::PASSWORD)->assertStatus(423);
    expect(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(1);
});

it('CA-08 shows the hidden cases separately: used invitation and wrong email', function () {
    $plain = invited($this);

    accept($this, 'nova@exemplo.com', $plain)->assertOk();
    accept($this, 'nova@exemplo.com', $plain)->assertUnprocessable()->assertJsonValidationErrors('token');
    accept($this, 'outra@exemplo.com', $plain)->assertUnprocessable()->assertJsonValidationErrors('token');
});
