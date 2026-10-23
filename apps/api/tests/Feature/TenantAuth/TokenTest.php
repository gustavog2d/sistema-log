<?php

use Illuminate\Support\Facades\DB;

it('CA-05 shows the person, the role, the business and whether writing is allowed', function () {
    $ws = $this->workspace();
    $token = $this->tokenFor();

    $this->asUser($token, 'GET', 'neg', 'auth/me')
        ->assertOk()
        ->assertExactJson([
            'user' => ['id' => $ws->user_id, 'name' => 'Pessoa owner', 'email' => 'ana@exemplo.com', 'role' => 'owner'],
            'tenant' => ['id' => $ws->tenant_id, 'name' => 'Neg', 'slug' => 'neg'],
            'can_write' => true,
            'impersonating' => false,
            'two_factor_enabled' => false,
        ]);
});

it('CA-04 does not accept the token of one business in another', function () {
    $this->workspace('primeiro', 'ana@exemplo.com');
    $userId = DB::connection('pgsql_admin')->table('users')->value('id');
    $this->workspace('segundo', 'ana@exemplo.com', 'owner', 'active', $userId);
    $token = $this->tokenFor('primeiro');

    $this->asUser($token, 'GET', 'primeiro', 'auth/me')->assertOk();
    $this->asUser($token, 'GET', 'segundo', 'auth/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
});

it('rejects missing, malformed, wrong and expired credentials with 401 problem details, never a 500', function (?string $header) {
    $this->workspace();
    $request = $header === null ? $this->getJson($this->authUrl('neg', 'auth/me')) : $this->withHeader('Authorization', $header)->getJson($this->authUrl('neg', 'auth/me'));

    $request->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
})->with([
    'sem cabeçalho' => [null],
    'esquema errado' => ['Basic abc'],
    'token vazio' => ['Bearer '],
    'token sem barra' => ['Bearer abcdef'],
    'uuid inventado' => ['Bearer 6f9619ff-8b86-d011-b42d-00c04fc964ff|abcdefghijklmnopqrstuvwxyzabcdefghijklmn'],
    'id inválido' => ['Bearer nao-e-uuid|abcdefghijklmnopqrstuvwxyzabcdefghijklmn'],
    'segredo curto' => ['Bearer 6f9619ff-8b86-d011-b42d-00c04fc964ff|curto'],
]);

it('rejects a token whose secret was tampered with', function () {
    $this->workspace();
    $token = $this->tokenFor();
    [$id] = explode('|', $token);

    $this->asUser($id.'|'.str_repeat('a', 40), 'GET', 'neg', 'auth/me')->assertUnauthorized();
});

it('CA-08 expires the token after twelve hours', function () {
    $this->workspace();
    $token = $this->tokenFor();

    $this->travel(11)->hours();
    $this->asUser($token, 'GET', 'neg', 'auth/me')->assertOk();

    $this->travel(2)->hours();
    $this->asUser($token, 'GET', 'neg', 'auth/me')->assertUnauthorized();
});

it('CA-06 revokes only the token used on logout', function () {
    $this->workspace();
    $first = $this->tokenFor();
    $second = $this->tokenFor();

    $this->asUser($first, 'POST', 'neg', 'auth/logout')->assertNoContent();

    $this->asUser($first, 'GET', 'neg', 'auth/me')->assertUnauthorized();
    $this->asUser($second, 'GET', 'neg', 'auth/me')->assertOk();
});

it('CA-07 invalidates every token of the person in every business on logout-all', function () {
    $this->workspace('primeiro', 'ana@exemplo.com');
    $userId = DB::connection('pgsql_admin')->table('users')->value('id');
    $this->workspace('segundo', 'ana@exemplo.com', 'owner', 'active', $userId);
    $a = $this->tokenFor('primeiro');
    $b = $this->tokenFor('segundo');
    $outra = $this->tokenFor('primeiro');

    $this->asUser($a, 'POST', 'primeiro', 'auth/logout-all')->assertNoContent();

    foreach ([[$a, 'primeiro'], [$b, 'segundo'], [$outra, 'primeiro']] as [$token, $slug]) {
        $this->asUser($token, 'GET', $slug, 'auth/me')->assertUnauthorized();
    }

    // Um acesso novo depois da saída geral funciona.
    $this->asUser($this->tokenFor('primeiro'), 'GET', 'primeiro', 'auth/me')->assertOk();
});

it('CA-21 requires login for the subscription status', function () {
    $this->workspace();

    $this->getJson($this->authUrl('neg', 'subscription'))->assertUnauthorized();
    $this->asUser($this->tokenFor(), 'GET', 'neg', 'subscription')->assertOk()->assertJsonPath('status', 'active');
});

it('stores only the hash of the token secret and updates the last use', function () {
    $this->workspace();
    $token = $this->tokenFor();
    [$id, $secret] = explode('|', $token);

    $row = DB::connection('pgsql_admin')->table('user_access_tokens')->where('id', $id)->first();
    expect($row->token_hash)->toBe(hash('sha256', $secret))->and($row->token_hash)->not->toContain($secret);
    expect($row->last_used_at)->toBeNull();

    $this->asUser($token, 'GET', 'neg', 'auth/me')->assertOk();

    expect(DB::connection('pgsql_admin')->table('user_access_tokens')->where('id', $id)->value('last_used_at'))->not->toBeNull();
});

it('does not let a challenge token reach protected routes', function () {
    $this->workspace();
    $this->enableTwoFactor();
    $challenge = $this->loginAt('neg')->assertOk()->json('challenge_token');

    $this->asUser($challenge, 'GET', 'neg', 'auth/me')->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
});

it('rejects a token of a person whose membership was removed', function () {
    $ws = $this->workspace();
    $token = $this->tokenFor();

    DB::connection('pgsql_admin')->table('memberships')->where('user_id', $ws->user_id)->delete();

    $this->asUser($token, 'GET', 'neg', 'auth/me')->assertUnauthorized();
});
