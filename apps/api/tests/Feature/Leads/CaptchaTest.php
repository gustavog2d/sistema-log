<?php

use App\Leads\CaptchaVerifier;
use App\Leads\FakeCaptchaVerifier;
use App\Leads\RecaptchaVerifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

it('CA-03 refuses a missing or wrong captcha token and stores nothing', function (array $override) {
    postLead($this, $override)->assertUnprocessable()->assertJsonValidationErrors('captcha_token');

    expect(leadCount())->toBe(0);
})->with([
    'missing' => [['captcha_token' => null]],
    'wrong' => [['captcha_token' => 'qualquer-coisa']],
    'empty' => [['captcha_token' => '']],
]);

it('CA-03 accepts the Google answer when it says success', function () {
    Http::fake(['www.google.com/*' => Http::response(['success' => true, 'score' => 0.9])]);
    $this->app->instance(CaptchaVerifier::class, new RecaptchaVerifier('segredo-de-teste'));

    postLead($this, ['captcha_token' => 'token-do-google'])->assertStatus(202);

    Http::assertSent(fn ($request) => $request['secret'] === 'segredo-de-teste' && $request['response'] === 'token-do-google');
});

it('CA-03 refuses when Google says no, when the score is low and when the service is down', function (callable $fake) {
    $fake();
    $this->app->instance(CaptchaVerifier::class, new RecaptchaVerifier('segredo-de-teste'));

    postLead($this, ['captcha_token' => 'token-do-google'])->assertUnprocessable()->assertJsonValidationErrors('captcha_token');
    expect(leadCount())->toBe(0);
})->with([
    'refused' => [fn () => Http::fake(['www.google.com/*' => Http::response(['success' => false])])],
    'low score' => [fn () => Http::fake(['www.google.com/*' => Http::response(['success' => true, 'score' => 0.1])])],
    'server error' => [fn () => Http::fake(['www.google.com/*' => Http::response('erro', 500)])],
    'connection failure' => [fn () => Http::fake(['www.google.com/*' => fn () => throw new ConnectionException('fora do ar')])],
]);

it('refuses to verify without a configured secret', function () {
    Http::fake();

    expect((new RecaptchaVerifier(''))->verify('qualquer', '127.0.0.1'))->toBeFalse();
    Http::assertNothingSent();
});

it('does not allow the fake verifier outside local and testing', function () {
    $this->app->detectEnvironment(fn () => 'production');
    $this->app->forgetInstance(CaptchaVerifier::class);
    config(['leads.captcha_driver' => 'fake']);

    expect(fn () => app(CaptchaVerifier::class))->toThrow(InvalidArgumentException::class);
});

it('uses the fake verifier by default in testing', function () {
    expect(app(CaptchaVerifier::class))->toBeInstanceOf(FakeCaptchaVerifier::class);
});
