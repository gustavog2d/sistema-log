<?php

use Illuminate\Support\Facades\DB;

it('CA-05 suspends by hand with a reason and blocks writes at once', function () {
    $ws = adminTenant($this, 'neg', 'active');

    adm($this, $this->adminToken(), 'POST', "tenants/{$ws->id}/suspend", ['reason' => 'Pedido do cliente'])->assertOk()->assertJsonPath('status', 'suspended')->assertJsonPath('suspended_reason', 'Pedido do cliente');

    $sub = DB::connection('pgsql_admin')->table('subscriptions')->first();
    expect($sub->status)->toBe('suspended')->and($sub->suspended_at)->not->toBeNull()->and($sub->suspended_reason)->toBe('Pedido do cliente');
});

it('CA-05 requires a reason', function (array $body) {
    $ws = adminTenant($this, 'neg', 'active');

    adm($this, $this->adminToken(), 'POST', "tenants/{$ws->id}/suspend", $body)->assertUnprocessable();
})->with([[[]], [['reason' => 'abc']], [['reason' => str_repeat('a', 201)]]]);

it('CA-06 lifts the suspension back to the right status', function (string $before, ?string $trialEnds, string $after) {
    $ws = adminTenant($this, 'neg', $before, array_filter(['trial_ends_at' => $trialEnds ? now()->{$trialEnds}() : null]));
    adm($this, $this->adminToken(), 'POST', "tenants/{$ws->id}/suspend", ['reason' => 'Motivo qualquer'])->assertOk();

    adm($this, $this->adminToken(), 'POST', "tenants/{$ws->id}/unsuspend")->assertOk()->assertJsonPath('status', $after);

    $sub = DB::connection('pgsql_admin')->table('subscriptions')->first();
    expect($sub->suspended_at)->toBeNull()->and($sub->suspended_reason)->toBeNull();
})->with([
    'active stays active' => ['active', null, 'active'],
    'trial still running' => ['trialing', 'addDays', 'trialing'],
]);

it('CA-07 refuses to suspend or unsuspend a canceled subscription, and unsuspend when it is not suspended', function () {
    $ws = adminTenant($this, 'neg', 'canceled');
    $admin = $this->adminToken();

    adm($this, $admin, 'POST', "tenants/{$ws->id}/suspend", ['reason' => 'Motivo qualquer'])->assertStatus(409);
    adm($this, $admin, 'POST', "tenants/{$ws->id}/unsuspend")->assertStatus(409);

    $other = adminTenant($this, 'outro', 'active', [], 'outra@exemplo.com');
    adm($this, $admin, 'POST', "tenants/{$other->id}/unsuspend")->assertStatus(409);
});

it('CA-08 extends the trial only while it is running', function () {
    $ws = adminTenant($this, 'neg', 'trialing', ['trial_ends_at' => $this->at('2026-12-01 12:00:00')]);

    adm($this, $this->adminToken(), 'POST', "tenants/{$ws->id}/extend-trial", ['days' => 7])->assertOk()->assertJsonPath('trial_ends_at', '2026-12-08T12:00:00+00:00');

    $active = adminTenant($this, 'outro', 'active', [], 'outra@exemplo.com');
    adm($this, $this->adminToken(), 'POST', "tenants/{$active->id}/extend-trial", ['days' => 7])->assertStatus(409);
});

it('CA-08 refuses days out of range', function (mixed $days) {
    $ws = adminTenant($this, 'neg', 'trialing', ['trial_ends_at' => now()->addDay()]);

    adm($this, $this->adminToken(), 'POST', "tenants/{$ws->id}/extend-trial", ['days' => $days])->assertUnprocessable();
})->with([0, 31, -3, 'dez']);

it('CA-12 keeps these actions for the super admin', function () {
    $ws = adminTenant($this, 'neg', 'trialing', ['trial_ends_at' => now()->addDay()]);
    $support = $this->supportToken();

    adm($this, $support, 'POST', "tenants/{$ws->id}/suspend", ['reason' => 'Motivo qualquer'])->assertForbidden();
    adm($this, $support, 'POST', "tenants/{$ws->id}/unsuspend")->assertForbidden();
    adm($this, $support, 'POST', "tenants/{$ws->id}/extend-trial", ['days' => 3])->assertForbidden();
});

it('CA-13 audits every action with who and why', function () {
    $ws = adminTenant($this, 'neg', 'trialing', ['trial_ends_at' => now()->addDay()]);
    $admin = $this->adminToken();

    adm($this, $admin, 'POST', "tenants/{$ws->id}/extend-trial", ['days' => 3])->assertOk();
    adm($this, $admin, 'POST', "tenants/{$ws->id}/suspend", ['reason' => 'Motivo qualquer'])->assertOk();
    adm($this, $admin, 'POST', "tenants/{$ws->id}/unsuspend")->assertOk();

    $events = DB::connection('pgsql_admin')->table('platform_audit_logs')->whereNotNull('platform_user_id')->pluck('event')->all();
    expect($events)->toContain('tenant.trial_extended', 'tenant.suspended', 'tenant.unsuspended');
});

it('CA-05 makes the write block visible to the business at once', function () {
    $ws = adminTenant($this, 'neg', 'active');
    $admin = $this->adminToken();
    $token = adm($this, $this->supportToken(), 'POST', "tenants/{$ws->id}/impersonate", ['reason' => 'Verificar o bloqueio manual'])->json('access_token');

    asTenant($this, $token, 'GET', 'neg', 'subscription')->assertOk()->assertJsonPath('can_write', true);
    adm($this, $admin, 'POST', "tenants/{$ws->id}/suspend", ['reason' => 'Motivo qualquer'])->assertOk();

    asTenant($this, $token, 'GET', 'neg', 'subscription')->assertOk()->assertJsonPath('can_write', false);
    asTenant($this, $token, 'POST', 'neg', 'users/invitations', ['name' => 'Nova Pessoa', 'email' => 'nova@exemplo.com', 'role' => 'reception'])->assertForbidden()->assertJsonPath('code', 'subscription_suspended');
});
