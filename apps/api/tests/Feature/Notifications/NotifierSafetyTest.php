<?php

use App\Notifications\Notifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

it('CA-10 completes the action and frees the key when the queue fails', function () {
    $this->seedCatalog();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('fila fora do ar'));

    $this->signup()->assertCreated();

    expect(DB::connection('pgsql_admin')->table('tenants')->count())->toBe(1)
        ->and(DB::connection('pgsql_admin')->table('notification_log')->count())->toBe(0);
});

it('CA-13 keeps no email address in the log', function () {
    Mail::fake();
    $this->seedCatalog();
    $this->signup()->assertCreated();

    $rows = DB::connection('pgsql_admin')->table('notification_log')->get();

    expect($rows)->toHaveCount(1)->and(json_encode($rows))->not->toContain('ana@exemplo.com')->not->toContain('@');
});

it('CA-14 sends nothing and does not fail when the business has no active owner', function () {
    Mail::fake();
    $ws = $this->workspace('neg', 'dona@exemplo.com');
    DB::connection('pgsql_admin')->table('memberships')->update(['status' => 'inactive']);

    app(Notifier::class)->toOwner($ws->tenant_id, 'trial_ending', 'k-sem-dona', ['until' => '01/01/2027']);

    expect(allQueued())->toBe(0)->and(DB::connection('pgsql_admin')->table('notification_log')->count())->toBe(0);
});

it('keeps the log away from the API role', function () {
    expect(DB::connection('pgsql_admin')->selectOne("select has_table_privilege('app_runtime', 'notification_log', 'SELECT') as ok")->ok)->toBeFalse();
});
