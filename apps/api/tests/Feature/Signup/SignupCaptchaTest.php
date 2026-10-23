<?php

use App\Leads\CaptchaVerifier;
use App\Leads\RecaptchaVerifier;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seedCatalog();
});

it('refuses a signup without the anti-robot token and creates nothing', function () {
    $this->signup(['captcha_token' => null])->assertUnprocessable()->assertJsonValidationErrors('captcha_token');

    $this->nothingWasCreated();
});

it('refuses a signup with a wrong anti-robot token and creates nothing', function () {
    $this->signup(['captcha_token' => 'qualquer-coisa'])->assertUnprocessable()->assertJsonValidationErrors('captcha_token');

    $this->nothingWasCreated();
});

it('repeats the same request with a new anti-robot token and gets the same answer (the token is out of the comparison)', function () {
    $first = $this->signup(['captcha_token' => 'captcha_ok'], 'mesma-chave')->assertCreated();
    $second = $this->signup(['captcha_token' => 'captcha_ok', 'slug' => 'tintaviva'], 'mesma-chave');

    $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
    expect($second->json())->toBe($first->json());
    expect($this->countAll('tenants'))->toBe(1);
});

it('still refuses a repeated key with different data even if the token changes', function () {
    $this->signup([], 'k1')->assertCreated();

    $this->signup(['business_name' => 'Outro Nome'], 'k1')->assertUnprocessable();
});

it('checks the action of the real captcha: a token asked for the contact form does not open a signup', function () {
    Http::fake(['www.google.com/*' => Http::response(['success' => true, 'score' => 0.9, 'action' => 'lead'])]);
    $this->app->instance(CaptchaVerifier::class, new RecaptchaVerifier('segredo'));

    $this->signup(['captcha_token' => 'token-do-formulario-de-contato'])->assertUnprocessable()->assertJsonValidationErrors('captcha_token');
    $this->nothingWasCreated();
});

it('accepts the real captcha answer for the signup action', function () {
    Http::fake(['www.google.com/*' => Http::response(['success' => true, 'score' => 0.9, 'action' => 'signup'])]);
    $this->app->instance(CaptchaVerifier::class, new RecaptchaVerifier('segredo'));

    $this->signup(['captcha_token' => 'token-ok'])->assertCreated();
});

it('can be turned off only by configuration', function () {
    config(['leads.signup_captcha' => false]);

    $this->signup(['captcha_token' => null])->assertCreated();
});
