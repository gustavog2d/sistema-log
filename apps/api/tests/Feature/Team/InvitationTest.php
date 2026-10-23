<?php

use App\Mail\InvitationMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

function invite($test, string $token, string $email, string $role = 'professional', string $slug = 'neg')
{
    return team($test, $token, 'POST', $slug, 'users/invitations', ['name' => 'Nova Pessoa', 'email' => $email, 'role' => $role]);
}

it('CA-02 lets the owner invite each role, mails the link after the answer and stores only the token hash', function (string $role) {
    [$token] = teamOf($this);

    invite($this, $token, 'Nova@Exemplo.com', $role)->assertCreated()->assertJsonPath('status', 'invited')->assertJsonPath('email', 'nova@exemplo.com')->assertJsonPath('role', $role);

    Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('nova@exemplo.com'));
    $plain = inviteToken('nova@exemplo.com');
    $row = DB::connection('pgsql_admin')->table('invitations')->first();

    expect($row->token_hash)->toBe(hash('sha256', $plain))
        ->and(json_encode($row))->not->toContain($plain)
        ->and(Carbon::parse($row->expires_at)->between(now()->addDays(6), now()->addDays(7)->addMinute()))->toBeTrue();
})->with(['manager', 'professional', 'reception']);

it('CA-03 refuses to invite an owner and validates the fields', function () {
    [$token] = teamOf($this);

    invite($this, $token, 'nova@exemplo.com', 'owner')->assertUnprocessable()->assertJsonValidationErrors('role');
    team($this, $token, 'POST', 'neg', 'users/invitations', ['name' => 'x', 'email' => 'invalido', 'role' => 'reception'])->assertUnprocessable();
});

it('CA-04 refuses someone who already belongs to the team (active or inactive)', function () {
    [$token] = teamOf($this, 'neg', ['ativa@exemplo.com' => 'reception', 'inativa@exemplo.com' => 'reception']);
    DB::connection('pgsql_admin')->table('memberships')->where('user_id', membershipOf('inativa@exemplo.com')->user_id)->update(['status' => 'inactive']);

    invite($this, $token, 'ativa@exemplo.com')->assertStatus(409)->assertJsonPath('code', 'already_member');
    invite($this, $token, 'INATIVA@exemplo.com')->assertStatus(409)->assertJsonPath('code', 'already_member');
});

it('CA-04 replaces the pending invitation when the same email is invited again', function () {
    [$token] = teamOf($this);

    invite($this, $token, 'nova@exemplo.com', 'reception')->assertCreated();
    $old = inviteToken('nova@exemplo.com');
    invite($this, $token, 'nova@exemplo.com', 'manager')->assertCreated();

    expect(DB::connection('pgsql_admin')->table('invitations')->count())->toBe(1)
        ->and(DB::connection('pgsql_admin')->table('invitations')->value('role'))->toBe('manager')
        ->and(DB::connection('pgsql_admin')->table('invitations')->value('token_hash'))->not->toBe(hash('sha256', $old));
});

it('CA-05 counts active people and pending invitations against the plan limit', function () {
    [$token] = teamOf($this);
    setLimit($this, 2);

    invite($this, $token, 'um@exemplo.com')->assertCreated();
    invite($this, $token, 'dois@exemplo.com')->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');
});

it('CA-05 has no limit when the plan does not set one', function () {
    [$token] = teamOf($this);

    foreach (range(1, 5) as $i) {
        invite($this, $token, "p{$i}@exemplo.com")->assertCreated();
    }
});

it('CA-14 cancels a pending invitation and the link stops working', function () {
    [$token] = teamOf($this);
    $id = invite($this, $token, 'nova@exemplo.com')->json('id');
    $plain = inviteToken('nova@exemplo.com');

    team($this, $token, 'DELETE', 'neg', "users/invitations/{$id}")->assertNoContent();
    team($this, $token, 'DELETE', 'neg', "users/invitations/{$id}")->assertNotFound();

    $this->postJson('http://neg.app.test/api/v1/auth/invitations/accept', ['email' => 'nova@exemplo.com', 'token' => $plain, 'password' => 'uma-senha-longa-123', 'accept_terms' => true])->assertUnprocessable();
});

it('CA-13 lists people and pending invitations without any secret', function () {
    [$token] = teamOf($this, 'neg', ['prof@exemplo.com' => 'professional']);
    invite($this, $token, 'nova@exemplo.com', 'reception')->assertCreated();
    $plain = inviteToken('nova@exemplo.com');

    $response = team($this, $token, 'GET', 'neg', 'users')->assertOk();

    expect(collect($response->json('members'))->pluck('role', 'email')->all())->toBe(['dona-neg@exemplo.com' => 'owner', 'prof@exemplo.com' => 'professional'])
        ->and($response->json('invitations.0.status'))->toBe('invited')
        ->and(json_encode($response->json()))->not->toContain($plain)->not->toContain('token_hash')->not->toContain('password');
});

it('refuses names that could carry a link or break the email text', function (string $name) {
    [$token] = teamOf($this);

    team($this, $token, 'POST', 'neg', 'users/invitations', ['name' => $name, 'email' => 'nova@exemplo.com', 'role' => 'reception'])->assertUnprocessable()->assertJsonValidationErrors('name');
})->with(["Maria\nhttp://x.com", 'Clique em http://golpe.com', 'A <b>', 'Maria@golpe']);

it('limits the invitations to the same recipient across businesses', function () {
    [$a] = teamOf($this, 'negocioa');
    [$b] = teamOf($this, 'negociob');

    foreach (range(1, 3) as $i) {
        team($this, $i % 2 ? $a : $b, 'POST', $i % 2 ? 'negocioa' : 'negociob', 'users/invitations', ['name' => 'Alvo Fixo', 'email' => 'alvo@exemplo.com', 'role' => 'reception'])->assertCreated();
    }

    team($this, $a, 'POST', 'negocioa', 'users/invitations', ['name' => 'Alvo Fixo', 'email' => 'alvo@exemplo.com', 'role' => 'reception'])->assertStatus(429);
});

it('requires accepting the terms to join', function () {
    [$token] = teamOf($this);
    team($this, $token, 'POST', 'neg', 'users/invitations', ['name' => 'Nova Pessoa', 'email' => 'nova@exemplo.com', 'role' => 'reception'])->assertCreated();

    $this->postJson('http://neg.app.test/api/v1/auth/invitations/accept', ['email' => 'nova@exemplo.com', 'token' => inviteToken('nova@exemplo.com'), 'password' => 'uma-senha-longa-123'])
        ->assertUnprocessable()->assertJsonValidationErrors('accept_terms');
});
