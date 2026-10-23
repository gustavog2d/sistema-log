<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

const TEAM_ROUTES = [
    ['GET', 'users', []],
    ['POST', 'users/invitations', ['name' => 'Nova Pessoa', 'email' => 'nova@exemplo.com', 'role' => 'professional']],
    ['DELETE', 'users/invitations/00000000-0000-4000-8000-000000000000', []],
    ['PATCH', 'users/00000000-0000-4000-8000-000000000000', ['role' => 'reception']],
    ['POST', 'users/00000000-0000-4000-8000-000000000000/deactivate', []],
    ['POST', 'users/00000000-0000-4000-8000-000000000000/reactivate', []],
];

it('CA-01 refuses professionals and receptionists on every team route', function (string $role) {
    teamOf($this, 'neg', ['pessoa@exemplo.com' => $role]);
    $token = tokenOf($this, 'neg', 'pessoa@exemplo.com');

    foreach (TEAM_ROUTES as [$method, $path, $body]) {
        team($this, $token, $method, 'neg', $path, $body)->assertForbidden();
    }
})->with(['professional', 'reception']);

it('CA-01 asks for a login on every team route', function () {
    teamOf($this);

    foreach (TEAM_ROUTES as [$method, $path, $body]) {
        $this->json($method, "http://neg.app.test/api/v1/{$path}", $body)->assertUnauthorized();
    }
});

it('CA-03 lets a manager invite staff but not an owner, and never touch the owner', function () {
    [, $ws] = teamOf($this, 'neg', ['gerente@exemplo.com' => 'manager']);
    $manager = tokenOf($this, 'neg', 'gerente@exemplo.com');
    $ownerId = membershipOf('dona-neg@exemplo.com')->user_id;

    team($this, $manager, 'POST', 'neg', 'users/invitations', ['name' => 'Nova', 'email' => 'nova@exemplo.com', 'role' => 'professional'])->assertCreated();
    team($this, $manager, 'POST', 'neg', 'users/invitations', ['name' => 'Dona', 'email' => 'dona2@exemplo.com', 'role' => 'owner'])->assertUnprocessable();
    team($this, $manager, 'PATCH', 'neg', "users/{$ownerId}", ['role' => 'reception'])->assertForbidden();
    team($this, $manager, 'POST', 'neg', "users/{$ownerId}/deactivate")->assertForbidden();
});

it('CA-15 keeps each business team apart', function () {
    [$tokenA] = teamOf($this, 'negocioa', ['a@exemplo.com' => 'professional']);
    [$tokenB] = teamOf($this, 'negociob', ['b@exemplo.com' => 'professional']);
    $bId = membershipOf('b@exemplo.com')->user_id;

    $listA = team($this, $tokenA, 'GET', 'negocioa', 'users')->assertOk();
    expect(collect($listA->json('members'))->pluck('email')->all())->toContain('a@exemplo.com')->not->toContain('b@exemplo.com');

    team($this, $tokenA, 'POST', 'negocioa', "users/{$bId}/deactivate")->assertNotFound();
    team($this, $tokenA, 'GET', 'negociob', 'users')->assertUnauthorized();
    expect(membershipOf('b@exemplo.com')->status)->toBe('active');
});

it('CA-16 lists the team but refuses changes when the subscription is suspended', function () {
    $this->seedBillingCatalog();
    [$token, $ws] = teamOf($this);
    DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $ws->tenant_id)->update(['status' => 'suspended']);

    team($this, $token, 'GET', 'neg', 'users')->assertOk();
    team($this, $token, 'POST', 'neg', 'users/invitations', ['name' => 'Nova', 'email' => 'nova@exemplo.com', 'role' => 'reception'])
        ->assertForbidden()->assertJsonPath('code', 'subscription_suspended');
});
