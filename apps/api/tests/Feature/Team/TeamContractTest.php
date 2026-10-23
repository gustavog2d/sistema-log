<?php

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    Mail::fake();
});

it('CA-17 returns bodies that respect the openapi contract', function () {
    $this->loadOpenApi('PLT-008-usuarios-e-papeis');
    [$token] = teamOf($this, 'neg', ['prof@exemplo.com' => 'professional']);
    $id = membershipOf('prof@exemplo.com')->user_id;

    $invited = team($this, $token, 'POST', 'neg', 'users/invitations', ['name' => 'Nova', 'email' => 'nova@exemplo.com', 'role' => 'reception'])->assertCreated();
    expect($this->schemaErrors($invited->json(), 'Invitation'))->toBe([]);

    $team = team($this, $token, 'GET', 'neg', 'users')->assertOk();
    expect($this->schemaErrors($team->json(), 'Team'))->toBe([]);

    expect($this->schemaErrors(team($this, $token, 'PATCH', 'neg', "users/{$id}", ['role' => 'manager'])->assertOk()->json(), 'Member'))->toBe([])
        ->and($this->schemaErrors(team($this, $token, 'POST', 'neg', "users/{$id}/deactivate")->assertOk()->json(), 'Member'))->toBe([])
        ->and($this->schemaErrors(team($this, $token, 'POST', 'neg', "users/{$id}/reactivate")->assertOk()->json(), 'Member'))->toBe([]);

    $accepted = $this->postJson('http://neg.app.test/api/v1/auth/invitations/accept', ['email' => 'nova@exemplo.com', 'token' => inviteToken('nova@exemplo.com'), 'password' => 'uma-senha-longa-123'])->assertOk();
    expect($this->schemaErrors($accepted->json(), 'Session'))->toBe([]);
});

it('CA-17 documents every status the routes can answer', function () {
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-008-usuarios-e-papeis/openapi.yaml'));
    $expected = [
        '/users' => ['get' => ['200', '401', '403']],
        '/users/invitations' => ['post' => ['201', '401', '403', '409', '422', '429']],
        '/users/invitations/{id}' => ['delete' => ['204', '401', '403', '404']],
        '/users/{id}' => ['patch' => ['200', '401', '403', '404', '422']],
        '/users/{id}/deactivate' => ['post' => ['200', '401', '403', '404', '422']],
        '/users/{id}/reactivate' => ['post' => ['200', '401', '403', '404', '409']],
        '/auth/invitations/accept' => ['post' => ['200', '422', '429']],
    ];

    foreach ($expected as $path => $operations) {
        foreach ($operations as $method => $codes) {
            expect(array_map('strval', array_keys($spec['paths'][$path][$method]['responses'])))->toContain(...$codes);
        }
    }
});
