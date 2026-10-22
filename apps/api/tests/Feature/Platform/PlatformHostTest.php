<?php

it('CA-09 hides platform routes on a tenant host', function () {
    $this->makeTenant('acme');

    $this->postJson('http://acme.app.test/api/v1/platform/auth/login', ['email' => 'a@b.co', 'password' => 'x'])
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-09 serves platform routes on the admin host', function () {
    $user = $this->platformUser();

    $this->postJson('http://admin.app.test/api/v1/platform/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])
        ->assertOk();
});
