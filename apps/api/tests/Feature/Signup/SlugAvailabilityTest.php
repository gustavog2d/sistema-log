<?php

it('CA-08 says a free address is available', function () {
    $this->getJson('http://app.test/api/v1/signup/slug-availability?slug=meuestudio')->assertOk()->assertExactJson(['available' => true]);
});

it('CA-08 explains why an address is not available', function (string $slug, string $reason) {
    $this->signupSeedAndTake();

    $this->getJson("http://app.test/api/v1/signup/slug-availability?slug={$slug}")->assertOk()->assertExactJson(['available' => false, 'reason' => $reason]);
})->with([
    'ocupado' => ['tintaviva', 'taken'],
    'reservado' => ['admin', 'reserved'],
    'inválido (maiúsculas)' => ['MeuEstudio', 'invalid'],
    'inválido (curto)' => ['ab', 'invalid'],
]);

it('answers 422 when the slug is missing or too long', function () {
    $this->getJson('http://app.test/api/v1/signup/slug-availability')->assertStatus(422);
    $this->getJson('http://app.test/api/v1/signup/slug-availability?slug='.str_repeat('a', 200))->assertStatus(422);
});

it('is not available on a tenant host', function () {
    $this->makeTenant('acme');

    $this->getJson('http://acme.app.test/api/v1/signup/slug-availability?slug=x')->assertNotFound();
});

it('limits availability checks per ip', function () {
    foreach (range(1, 60) as $_) {
        $this->getJson('http://app.test/api/v1/signup/slug-availability?slug=livre')->assertOk();
    }

    $this->getJson('http://app.test/api/v1/signup/slug-availability?slug=livre')->assertStatus(429);
});
