<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();
});

it('CA-09 lets the owner change a role and refuses own role, the owner and the owner role', function () {
    [$token] = teamOf($this, 'neg', ['prof@exemplo.com' => 'professional']);
    $id = membershipOf('prof@exemplo.com')->user_id;
    $ownerId = membershipOf('dona-neg@exemplo.com')->user_id;

    team($this, $token, 'PATCH', 'neg', "users/{$id}", ['role' => 'reception'])->assertOk()->assertJsonPath('role', 'reception');
    expect(membershipOf('prof@exemplo.com')->role)->toBe('reception');

    team($this, $token, 'PATCH', 'neg', "users/{$id}", ['role' => 'owner'])->assertUnprocessable();
    team($this, $token, 'PATCH', 'neg', "users/{$ownerId}", ['role' => 'manager'])->assertUnprocessable();
});

it('CA-09 does not let a manager change their own role', function () {
    teamOf($this, 'neg', ['gerente@exemplo.com' => 'manager']);
    $manager = tokenOf($this, 'neg', 'gerente@exemplo.com');
    $id = membershipOf('gerente@exemplo.com')->user_id;

    team($this, $manager, 'PATCH', 'neg', "users/{$id}", ['role' => 'professional'])->assertUnprocessable();
});

it('CA-10 drops the tokens at once, blocks the login and keeps the data when a person is deactivated', function () {
    [$owner] = teamOf($this, 'neg', ['prof@exemplo.com' => 'professional']);
    $profToken = tokenOf($this, 'neg', 'prof@exemplo.com');
    $id = membershipOf('prof@exemplo.com')->user_id;
    team($this, $profToken, 'GET', 'neg', 'auth/me')->assertOk();

    team($this, $owner, 'POST', 'neg', "users/{$id}/deactivate")->assertOk()->assertJsonPath('status', 'inactive');

    team($this, $profToken, 'GET', 'neg', 'auth/me')->assertUnauthorized();
    $this->loginAt('neg', 'prof@exemplo.com')->assertUnauthorized();
    expect(DB::connection('pgsql_admin')->table('users')->where('email', 'prof@exemplo.com')->exists())->toBeTrue()
        ->and(DB::connection('pgsql_admin')->table('user_access_tokens')->where('user_id', $id)->count())->toBe(0);
});

it('CA-10 keeps the sessions the person has in other businesses', function () {
    $this->workspace('outro', 'viajante@exemplo.com');
    [$owner] = teamOf($this, 'neg', ['viajante@exemplo.com' => 'professional']);
    $otherToken = tokenOf($this, 'outro', 'viajante@exemplo.com');
    $id = membershipOf('viajante@exemplo.com')->user_id;

    team($this, $owner, 'POST', 'neg', "users/{$id}/deactivate")->assertOk();

    team($this, $otherToken, 'GET', 'outro', 'auth/me')->assertOk();
});

it('CA-11 reactivates with the previous role and respects the limit', function () {
    [$owner] = teamOf($this, 'neg', ['prof@exemplo.com' => 'professional']);
    $id = membershipOf('prof@exemplo.com')->user_id;
    team($this, $owner, 'POST', 'neg', "users/{$id}/deactivate")->assertOk();

    setLimit($this, 1);
    team($this, $owner, 'POST', 'neg', "users/{$id}/reactivate")->assertStatus(409)->assertJsonPath('code', 'plan_limit_reached');

    setLimit($this, 5);
    team($this, $owner, 'POST', 'neg', "users/{$id}/reactivate")->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('role', 'professional');
    $this->loginAt('neg', 'prof@exemplo.com')->assertOk();
});

it('CA-12 refuses to deactivate yourself or the owner', function () {
    [$owner] = teamOf($this, 'neg', ['gerente@exemplo.com' => 'manager']);
    $ownerId = membershipOf('dona-neg@exemplo.com')->user_id;
    $managerToken = tokenOf($this, 'neg', 'gerente@exemplo.com');
    $managerId = membershipOf('gerente@exemplo.com')->user_id;

    team($this, $owner, 'POST', 'neg', "users/{$ownerId}/deactivate")->assertUnprocessable();
    team($this, $managerToken, 'POST', 'neg', "users/{$managerId}/deactivate")->assertUnprocessable();
});

it('answers 404 for a person who is not in this business', function () {
    [$owner] = teamOf($this);

    team($this, $owner, 'POST', 'neg', 'users/'.Str::uuid().'/deactivate')->assertNotFound();
    team($this, $owner, 'PATCH', 'neg', 'users/'.Str::uuid(), ['role' => 'reception'])->assertNotFound();
});

it('CA-09 lets a manager change the role of another member but not give the owner role', function () {
    teamOf($this, 'neg', ['gerente@exemplo.com' => 'manager', 'prof@exemplo.com' => 'professional']);
    $manager = tokenOf($this, 'neg', 'gerente@exemplo.com');
    $id = membershipOf('prof@exemplo.com')->user_id;

    team($this, $manager, 'PATCH', 'neg', "users/{$id}", ['role' => 'reception'])->assertOk()->assertJsonPath('role', 'reception');
    team($this, $manager, 'PATCH', 'neg', "users/{$id}", ['role' => 'owner'])->assertUnprocessable();
});

it('CA-13 shows inactive people, hides expired invitations and invitations of other businesses', function () {
    [$owner] = teamOf($this, 'neg', ['prof@exemplo.com' => 'professional']);
    [$otherOwner] = teamOf($this, 'outro');
    DB::connection('pgsql_admin')->table('memberships')->where('user_id', membershipOf('prof@exemplo.com')->user_id)->update(['status' => 'inactive']);

    team($this, $owner, 'POST', 'neg', 'users/invitations', ['name' => 'Velha', 'email' => 'velha@exemplo.com', 'role' => 'reception'])->assertCreated();
    team($this, $owner, 'POST', 'neg', 'users/invitations', ['name' => 'Nova', 'email' => 'nova@exemplo.com', 'role' => 'reception'])->assertCreated();
    team($this, $otherOwner, 'POST', 'outro', 'users/invitations', ['name' => 'Alheia', 'email' => 'alheia@exemplo.com', 'role' => 'reception'])->assertCreated();
    DB::connection('pgsql_admin')->table('invitations')->where('email', 'velha@exemplo.com')->update(['expires_at' => now()->subMinute()]);

    $list = team($this, $owner, 'GET', 'neg', 'users')->assertOk();

    expect(collect($list->json('members'))->firstWhere('email', 'prof@exemplo.com')['status'])->toBe('inactive')
        ->and(collect($list->json('invitations'))->pluck('email')->all())->toBe(['nova@exemplo.com']);
});

it('CA-16 refuses every team change while the subscription is suspended', function () {
    [$owner, $ws] = teamOf($this, 'neg', ['prof@exemplo.com' => 'professional']);
    $id = membershipOf('prof@exemplo.com')->user_id;
    $inviteId = team($this, $owner, 'POST', 'neg', 'users/invitations', ['name' => 'Nova', 'email' => 'nova@exemplo.com', 'role' => 'reception'])->json('id');
    DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $ws->tenant_id)->update(['status' => 'suspended']);

    foreach ([['PATCH', "users/{$id}", ['role' => 'reception']], ['POST', "users/{$id}/deactivate", []], ['POST', "users/{$id}/reactivate", []], ['DELETE', "users/invitations/{$inviteId}", []]] as [$method, $path, $body]) {
        team($this, $owner, $method, 'neg', $path, $body)->assertForbidden()->assertJsonPath('code', 'subscription_suspended');
    }
});

it('CA-01 answers 401 (not 403) to a write without login even when the subscription is suspended', function () {
    [, $ws] = teamOf($this);
    DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $ws->tenant_id)->update(['status' => 'suspended']);

    $this->postJson('http://neg.app.test/api/v1/users/invitations', ['name' => 'Nova', 'email' => 'nova@exemplo.com', 'role' => 'reception'])->assertUnauthorized();
});
