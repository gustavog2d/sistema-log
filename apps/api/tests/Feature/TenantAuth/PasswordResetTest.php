<?php

use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

function forgot($test, string $slug, string $email)
{
    return $test->postJson("http://{$slug}.app.test/api/v1/auth/password/forgot", ['email' => $email]);
}

function sentToken(string $email): string
{
    $mail = Mail::sent(PasswordResetMail::class, fn ($m) => $m->hasTo($email))->last();

    return $mail->token;
}

beforeEach(function () {
    Mail::fake();
    $this->workspace('neg', 'ana@exemplo.com');
    $this->workspace('outro', 'beto@exemplo.com');
});

it('CA-10 answers 202 for a member, a non member and an unknown email, and mails only the member', function () {
    forgot($this, 'neg', 'ana@exemplo.com')->assertStatus(202);
    forgot($this, 'neg', 'beto@exemplo.com')->assertStatus(202);
    forgot($this, 'neg', 'ninguem@exemplo.com')->assertStatus(202);

    Mail::assertSent(PasswordResetMail::class, 1);
    Mail::assertSent(PasswordResetMail::class, fn ($m) => $m->hasTo('ana@exemplo.com'));
});

it('CA-10 gives the same answer body for every case so nothing leaks', function () {
    $bodies = collect(['ana@exemplo.com', 'beto@exemplo.com', 'ninguem@exemplo.com'])
        ->map(fn ($email) => forgot($this, 'neg', $email)->getContent());

    expect($bodies->unique()->count())->toBe(1);
});

it('CA-10 stores only the hash of the token and builds a link for this business', function () {
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');

    $row = DB::connection('pgsql_admin')->table('password_reset_tokens')->where('email', 'ana@exemplo.com')->first();
    expect($row->token_hash)->toBe(hash('sha256', $token))->and($row->token_hash)->not->toContain($token);

    $mail = Mail::sent(PasswordResetMail::class)->first();
    expect($mail->link)->toContain('neg.app.test')->toContain($token)->toContain(urlencode('ana@exemplo.com'));
});

it('CA-10 limits recovery requests to five per hour per email', function () {
    foreach (range(1, 5) as $i) {
        forgot($this, 'neg', 'ana@exemplo.com')->assertStatus(202);
    }

    forgot($this, 'neg', 'ana@exemplo.com')->assertStatus(429)->assertHeader('Content-Type', 'application/problem+json');
});

it('validates the forgot payload', function () {
    $this->postJson($this->authUrl('neg', 'auth/password/forgot'), [])->assertStatus(422);
    $this->postJson($this->authUrl('neg', 'auth/password/forgot'), ['email' => 'x'])->assertStatus(422);
});

it('CA-11 sets the new password with a valid token, consumes it and drops the old accesses', function () {
    $old = $this->tokenFor('neg', 'ana@exemplo.com');
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');

    $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $token, 'password' => 'outra-senha-bem-forte-1'])->assertNoContent();

    $this->loginAt('neg', 'ana@exemplo.com', self::PASSWORD)->assertUnauthorized();
    $this->loginAt('neg', 'ana@exemplo.com', 'outra-senha-bem-forte-1')->assertOk();
    $this->asUser($old, 'GET', 'neg', 'auth/me')->assertUnauthorized();
    expect(DB::connection('pgsql_admin')->table('password_reset_tokens')->count())->toBe(0);
});

it('CA-11 accepts a recovery token only once', function () {
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');
    $payload = ['email' => 'ana@exemplo.com', 'token' => $token, 'password' => 'outra-senha-bem-forte-1'];

    $this->postJson($this->authUrl('neg', 'auth/password/reset'), $payload)->assertNoContent();
    $this->postJson($this->authUrl('neg', 'auth/password/reset'), $payload)->assertStatus(422)->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-11 refuses an expired token', function () {
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');

    $this->travel(61)->minutes();

    $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $token, 'password' => 'outra-senha-bem-forte-1'])->assertStatus(422);
});

it('CA-11 accepts a token still inside its hour', function () {
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');

    $this->travel(59)->minutes();

    $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $token, 'password' => 'outra-senha-bem-forte-1'])->assertNoContent();
});

it('CA-11 refuses a wrong token, a token for another email and answers all of them the same way', function () {
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');
    $password = 'outra-senha-bem-forte-1';

    $wrong = $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => str_repeat('a', 64), 'password' => $password]);
    $otherEmail = $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'beto@exemplo.com', 'token' => $token, 'password' => $password]);
    $unknown = $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ninguem@exemplo.com', 'token' => $token, 'password' => $password]);

    foreach ([$wrong, $otherEmail, $unknown] as $response) {
        $response->assertStatus(422);
    }
    expect(collect([$wrong, $otherEmail, $unknown])->map(fn ($r) => $r->json())->unique()->count())->toBe(1);
});

it('CA-11 only honours the latest token when a new recovery is requested', function () {
    forgot($this, 'neg', 'ana@exemplo.com');
    $first = sentToken('ana@exemplo.com');
    forgot($this, 'neg', 'ana@exemplo.com');
    $second = sentToken('ana@exemplo.com');

    $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $first, 'password' => 'outra-senha-bem-forte-1'])->assertStatus(422);
    $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $second, 'password' => 'outra-senha-bem-forte-1'])->assertNoContent();
});

it('CA-11 unlocks a locked account when the password is redefined', function () {
    foreach (range(1, 5) as $_) {
        $this->loginAt('neg', 'ana@exemplo.com', 'senha-errada-123');
    }
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');
    $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $token, 'password' => 'outra-senha-bem-forte-1'])->assertNoContent();

    $this->loginAt('neg', 'ana@exemplo.com', 'outra-senha-bem-forte-1')->assertOk();
});

it('CA-13 refuses weak or too long passwords on reset', function (string $password) {
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');

    $this->postJson($this->authUrl('neg', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $token, 'password' => $password])->assertStatus(422);
    $this->loginAt('neg')->assertOk();
})->with(['curta' => ['curta'], 'onze caracteres' => [str_repeat('a', 11)], 'mais de 72 bytes' => [str_repeat('a', 73)]]);

it('does not let a token requested for a business reset the password of someone who is not a member there', function () {
    forgot($this, 'neg', 'ana@exemplo.com');
    $token = sentToken('ana@exemplo.com');

    $this->postJson($this->authUrl('outro', 'auth/password/reset'), ['email' => 'ana@exemplo.com', 'token' => $token, 'password' => 'outra-senha-bem-forte-1'])->assertStatus(422);
});
