<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->loadOpenApi('PLT-006-login-do-assinante');
    $this->workspace();
});

it('CA-22 returns bodies that respect the contract schemas', function () {
    $login = $this->loginAt('neg')->assertOk()->json();
    expect($this->schemaErrors($login, 'Session'))->toBe([]);

    $me = $this->asUser($login['access_token'], 'GET', 'neg', 'auth/me')->assertOk()->json();
    expect($this->schemaErrors($me, 'Me'))->toBe([]);

    $setup = $this->asUser($login['access_token'], 'POST', 'neg', 'auth/2fa/setup')->assertOk()->json();
    expect($this->schemaErrors($setup, 'TwoFactorSetup'))->toBe([]);

    $confirmed = $this->asUser($login['access_token'], 'POST', 'neg', 'auth/2fa/confirm', ['code' => $this->totp($setup['secret'])])->assertOk()->json();
    expect($this->schemaErrors($confirmed, 'TwoFactorConfirmed'))->toBe([]);

    $challenge = $this->loginAt('neg')->assertOk()->json();
    expect($this->schemaErrors($challenge, 'Challenge'))->toBe([]);
});

it('CA-22 returns problem details that respect the contract', function () {
    $wrong = $this->loginAt('neg', 'ana@exemplo.com', 'senha-errada-123')->assertUnauthorized()->json();
    $invalid = $this->postJson($this->authUrl('neg', 'auth/login'), [])->assertStatus(422)->json();

    expect($this->schemaErrors($wrong, 'Problem'))->toBe([])->and($this->schemaErrors($invalid, 'Problem'))->toBe([]);
});

it('declares every route of the feature in the contract', function () {
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-006-login-do-assinante/openapi.yaml'));
    $declared = array_keys($spec['paths']);

    foreach (['/auth/login', '/auth/2fa/verify', '/auth/me', '/auth/logout', '/auth/logout-all', '/auth/password/forgot', '/auth/password/reset', '/auth/password/change', '/auth/2fa/setup', '/auth/2fa/confirm', '/auth/2fa/disable'] as $path) {
        expect($declared)->toContain($path);
    }

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/auth/'))->map(fn ($r) => '/'.substr($r->uri(), strlen('api/v1') + 1))->reject(fn ($path) => $path === '/auth/invitations/accept') // contrato do PLT-008
        ->unique()->sort()->values()->all();
    expect($routes)->toBe(collect($declared)->sort()->values()->all());
});
