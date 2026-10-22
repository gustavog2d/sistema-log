<?php

it('CA-06 answers the same way for unknown email, wrong password and inactive user', function () {
    $active = $this->platformUser();
    $inactive = $this->platformUser(attributes: ['active' => false]);

    $responses = [
        $this->login('ninguem@exemplo.com'),
        $this->login($active->email, 'senha-errada-123'),
        $this->login($inactive->email),
    ];

    foreach ($responses as $response) {
        $response->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
    }

    expect(collect($responses)->map(fn ($r) => $r->json())->unique()->count())->toBe(1);
});

it('CA-07 locks the account after 5 failures and releases it after 15 minutes', function () {
    $user = $this->platformUser();

    foreach (range(1, 5) as $_) {
        $this->login($user->email, 'senha-errada-123')->assertUnauthorized();
    }

    $this->login($user->email)->assertStatus(423)->assertHeader('Content-Type', 'application/problem+json');

    $this->travel(16)->minutes();

    $this->login($user->email)->assertOk();
});

it('CA-07 locks on repeated wrong 2fa codes too', function () {
    $user = $this->platformUser();

    foreach (range(1, 5) as $_) {
        $challenge = $this->login($user->email)->json('challenge_token');
        $this->asToken($challenge, 'POST', 'auth/2fa/verify', ['code' => '000000'])->assertUnauthorized();
    }

    $this->login($user->email)->assertStatus(423);
});

it('CA-14 limits login attempts per ip', function () {
    foreach (range(1, 10) as $i) {
        $this->login("pessoa{$i}@exemplo.com")->assertUnauthorized();
    }

    $this->login('pessoa11@exemplo.com')->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');
});

it('validates the login payload', function () {
    $this->postJson($this->platformUrl('auth/login'), [])->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
});
