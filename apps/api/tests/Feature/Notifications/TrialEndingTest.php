<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->seedBillingCatalog();
});

function trialOf(string $slug, array $overrides = []): object
{
    return (fn () => $this->workspace($slug, "dona-{$slug}@exemplo.com", 'owner', 'trialing'))->call(test());
}

it('CA-02 warns once when the trial ends within two days', function () {
    $ws = $this->workspace('neg', 'dona@exemplo.com', 'owner', 'trialing');
    DB::connection('pgsql_admin')->table('subscriptions')->update(['trial_ends_at' => now()->addDays(2)->subHour()]);

    $this->artisan('notifications:run')->assertSuccessful();
    $this->artisan('notifications:run')->assertSuccessful();

    expect(queuedCount('trial_ending', 'dona@exemplo.com'))->toBe(1);
});

it('CA-03 does not warn when the trial is far from the end, when a cancellation is pending, or for a courtesy', function () {
    $this->workspace('longe', 'longe@exemplo.com', 'owner', 'trialing');
    $this->workspace('cancelada', 'cancelada@exemplo.com', 'owner', 'trialing');
    $this->workspace('cortesia', 'cortesia@exemplo.com', 'owner', 'trialing');
    $db = DB::connection('pgsql_admin');
    $tenant = fn (string $slug) => $db->table('tenants')->where('slug', $slug)->value('id');
    $db->table('subscriptions')->where('tenant_id', $tenant('longe'))->update(['trial_ends_at' => now()->addDays(5)]);
    $db->table('subscriptions')->where('tenant_id', $tenant('cancelada'))->update(['trial_ends_at' => now()->addDay(), 'canceled_at' => now()]);
    $db->table('subscriptions')->where('tenant_id', $tenant('cortesia'))->update(['trial_ends_at' => now()->addDay(), 'price_cents' => 0]);

    $this->artisan('notifications:run')->assertSuccessful();

    expect(allQueued())->toBe(0);
});

it('CA-02 does not warn about a trial that already ended', function () {
    $this->workspace('neg', 'dona@exemplo.com', 'owner', 'trialing');
    DB::connection('pgsql_admin')->table('subscriptions')->update(['trial_ends_at' => now()->subHour()]);

    $this->artisan('notifications:run')->assertSuccessful();

    expect(allQueued())->toBe(0);
});

it('simulates time with --as-of only in local and testing', function () {
    $this->workspace('neg', 'dona@exemplo.com', 'owner', 'trialing');
    DB::connection('pgsql_admin')->table('subscriptions')->update(['trial_ends_at' => $this->at('2027-03-10 12:00:00')]);

    $this->artisan('notifications:run', ['--as-of' => '2027-03-09T12:00:00Z'])->assertSuccessful();

    expect(queuedCount('trial_ending'))->toBe(1);
    $this->artisan('notifications:run', ['--as-of' => 'não é data'])->assertFailed();
});
