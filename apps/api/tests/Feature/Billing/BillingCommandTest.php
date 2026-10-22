<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->seedBillingCatalog());

it('CA-15 simulates time with --as-of and prints a summary', function () {
    $sub = $this->subscription(['trial_ends_at' => $this->at('2026-12-01 09:00:00')]);

    $this->artisan('billing:run', ['--as-of' => '2026-12-01 10:00:00'])
        ->expectsOutputToContain('Cobradas: 1')
        ->expectsOutputToContain('Suspensas: 0')
        ->assertSuccessful();

    expect($this->subscriptionRow($sub->id)->status)->toBe('active');
});

it('CA-15 uses the current time when --as-of is not given', function () {
    $sub = $this->subscription();

    $this->artisan('billing:run')->assertSuccessful();

    expect($this->subscriptionRow($sub->id)->status)->toBe('active');
});

it('CA-15 refuses an invalid --as-of date', function () {
    $this->artisan('billing:run', ['--as-of' => 'ontem de manhã'])
        ->expectsOutputToContain('Data inválida')
        ->assertFailed();
});

it('CA-15 reports suspensions and errors in the summary', function () {
    $this->subscription([], 'pm_fake_fail_x');

    $this->artisan('billing:run')->expectsOutputToContain('Suspensas: 1')->assertSuccessful();
});

it('CA-17 is scheduled every five minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'billing:run'));

    expect($event)->not->toBeNull();
    expect($event->expression)->toBe('*/5 * * * *');
    expect($event->withoutOverlapping)->toBeTrue();
});

it('does not depend on the real clock of the machine when --as-of is used', function () {
    Carbon::setTestNow($this->at('2020-01-01 00:00:00'));
    $sub = $this->subscription(['trial_ends_at' => $this->at('2026-12-01 09:00:00')]);

    $this->artisan('billing:run', ['--as-of' => '2026-12-02 00:00:00'])->assertSuccessful();

    expect($this->subscriptionRow($sub->id)->status)->toBe('active');
    Carbon::setTestNow();
});
