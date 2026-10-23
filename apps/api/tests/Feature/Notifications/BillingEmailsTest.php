<?php

use App\Notifications\TransactionalMail;
use Illuminate\Contracts\Mail\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->seedBillingCatalog();
});

function dueTrial(object $test, string $methodRef = 'pm_fake_ok_x', array $subscription = []): object
{
    $ws = (fn () => $this->workspace('neg', 'dona@exemplo.com', 'owner', 'trialing'))->call($test);
    $db = DB::connection('pgsql_admin');
    $db->table('payment_methods')->where('tenant_id', $ws->tenant_id)->update(['gateway_ref' => $methodRef]);
    $db->table('subscriptions')->where('tenant_id', $ws->tenant_id)->update($subscription + ['trial_ends_at' => now()->subMinute()]);

    return $ws;
}

it('CA-04 sends one payment confirmation even when billing runs twice', function () {
    dueTrial($this);

    $this->artisan('billing:run')->assertSuccessful();
    $this->artisan('billing:run')->assertSuccessful();

    expect(queuedCount('payment_succeeded', 'dona@exemplo.com'))->toBe(1);
});

it('CA-05 warns about the suspension and does not send a payment confirmation', function () {
    dueTrial($this, 'pm_fake_fail_x');

    $this->artisan('billing:run')->assertSuccessful();
    $this->artisan('billing:run')->assertSuccessful();

    expect(queuedCount('subscription_suspended', 'dona@exemplo.com'))->toBe(1)->and(queuedCount('payment_succeeded'))->toBe(0);
});

it('CA-12 sends no payment confirmation for a courtesy account', function () {
    dueTrial($this, 'pm_fake_ok_x', ['price_cents' => 0]);

    $this->artisan('billing:run')->assertSuccessful();

    expect(allQueued())->toBe(0);
});

it('CA-06 confirms a regularization and sends nothing when it is refused', function () {
    $this->workspace('neg', 'dona@exemplo.com', 'owner', 'suspended');
    $token = $this->tokenFor('neg', 'dona@exemplo.com');

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_fail_on_charge'], 'k1')->assertStatus(402);
    expect(allQueued())->toBe(0);

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'k2')->assertOk();
    expect(queuedCount('subscription_regularized', 'dona@exemplo.com'))->toBe(1);
});

it('CA-07 confirms the cancellation request with the date, and the end of the subscription', function () {
    $this->workspace('neg', 'dona@exemplo.com', 'owner', 'active');
    DB::connection('pgsql_admin')->table('subscriptions')->update(['current_period_end' => $this->at('2026-12-01 09:00:00')]);
    $token = $this->tokenFor('neg', 'dona@exemplo.com');

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();
    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();

    expect(queuedCount('cancel_requested', 'dona@exemplo.com'))->toBe(1);
    Mail::assertQueued(TransactionalMail::class, fn ($m) => $m->kind === 'cancel_requested' && $m->data['until'] === '01/12/2026');

    $this->artisan('billing:run', ['--as-of' => '2026-12-01T10:00:00Z'])->assertSuccessful();
    $this->artisan('billing:run', ['--as-of' => '2026-12-01T11:00:00Z'])->assertSuccessful();

    expect(queuedCount('subscription_canceled', 'dona@exemplo.com'))->toBe(1);
});

it('CA-07 tells a suspended subscription that was canceled', function () {
    $this->workspace('neg', 'dona@exemplo.com', 'owner', 'suspended');
    $token = $this->tokenFor('neg', 'dona@exemplo.com');

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();

    expect(queuedCount('subscription_canceled', 'dona@exemplo.com'))->toBe(1);
});

it('CA-07 tells the trial end date when the cancellation is asked during the trial', function () {
    $this->workspace('neg', 'dona@exemplo.com', 'owner', 'trialing');
    DB::connection('pgsql_admin')->table('subscriptions')->update(['trial_ends_at' => $this->at('2026-12-05 15:00:00')]);
    $token = $this->tokenFor('neg', 'dona@exemplo.com');

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();

    Mail::assertQueued(TransactionalMail::class, fn ($m) => $m->kind === 'cancel_requested' && $m->data['until'] === '05/12/2026');
});

it('shows dates in the time zone of the business', function () {
    $this->workspace('neg', 'dona@exemplo.com', 'owner', 'active');
    DB::connection('pgsql_admin')->table('tenants')->update(['timezone' => 'Asia/Tokyo']);
    DB::connection('pgsql_admin')->table('subscriptions')->update(['current_period_end' => $this->at('2026-12-01 20:00:00')]);
    $token = $this->tokenFor('neg', 'dona@exemplo.com');

    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();

    Mail::assertQueued(TransactionalMail::class, fn ($m) => $m->kind === 'cancel_requested' && $m->data['until'] === '02/12/2026');
});

it('CA-10 keeps the charge when the queue is down', function () {
    $ws = dueTrial($this);
    Mail::swap(Mockery::mock(Factory::class, fn ($mock) => $mock->shouldReceive('to')->andThrow(new RuntimeException('fila fora do ar'))));

    $this->artisan('billing:run')->assertSuccessful();

    expect(DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $ws->tenant_id)->value('status'))->toBe('active')
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(1);
});

it('says there is no payment method when that is the reason for the suspension', function () {
    $ws = dueTrial($this);
    DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $ws->tenant_id)->update(['payment_method_id' => null]);

    $this->artisan('billing:run')->assertSuccessful();

    Mail::assertQueued(TransactionalMail::class, fn ($m) => $m->kind === 'subscription_suspended' && str_contains($m->data['reason'], 'meio de pagamento'));
});
