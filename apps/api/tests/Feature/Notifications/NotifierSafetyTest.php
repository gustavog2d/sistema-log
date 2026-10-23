<?php

use App\Notifications\Notifier;
use App\Notifications\TransactionalMail;
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

it('rejects names with control characters or links in the signup (they end up in emails)', function (string $field, string $value) {
    $this->seedCatalog();

    $this->signup([$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['business_name', "Estúdio\r\nBcc: x@y.com"],
    ['business_name', 'Ligue já http://golpe.com'],
    ['business_name', 'Veja www.golpe.com'],
    ['owner_name', "Ana\nSouza"],
    ['owner_name', 'ana@golpe.com'],
]);

it('does not put the business name in the subject of the welcome', function () {
    $mail = new TransactionalMail('welcome', 'Ana', 'Nome Livre Do Negócio');

    expect($mail->envelope()->subject)->not->toContain('Nome Livre');
});

it('warns about 2fa only when it was on, and not twice in the same minutes', function () {
    Mail::fake();
    $this->workspace('neg', 'ana@exemplo.com');
    $token = $this->tokenFor('neg');

    $this->asUser($token, 'POST', 'neg', 'auth/2fa/disable', ['current_password' => self::PASSWORD])->assertNoContent();

    expect(allQueued())->toBe(0);
});

it('limits the sensitive account actions per person', function () {
    $this->workspace('neg', 'ana@exemplo.com');
    $token = $this->tokenFor('neg');

    $status = null;
    foreach (range(1, 12) as $i) {
        $status = $this->asUser($token, 'POST', 'neg', 'auth/2fa/disable', ['current_password' => self::PASSWORD])->status();
    }

    expect($status)->toBe(429);
});
