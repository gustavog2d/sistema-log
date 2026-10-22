<?php

use App\Billing\ChargeResult;
use App\Billing\Fake\FakePaymentGateway;
use App\Billing\PaymentGateway;
use App\Billing\SubscriptionBilling;
use App\Models\PlatformUser;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
    Carbon::setTestNow($this->at('2026-11-10 12:00:00'));
});

afterEach(fn () => Carbon::setTestNow());

function runBilling(?Carbon $asOf = null): object
{
    return app(SubscriptionBilling::class)->run($asOf ?? now());
}

it('CA-01 charges the signup price when the trial ends and starts the first period at the due date', function () {
    $sub = $this->subscription(['trial_ends_at' => $this->at('2026-11-10 09:00:00'), 'price_cents' => 5000]);

    $summary = runBilling();

    $row = $this->subscriptionRow($sub->id);
    expect($row->status)->toBe('active');
    expect(Carbon::parse($row->current_period_start)->toDateTimeString())->toBe('2026-11-10 09:00:00');
    expect(Carbon::parse($row->current_period_end)->toDateTimeString())->toBe('2026-12-10 09:00:00');

    $invoice = $this->invoicesOf($sub->id)[0];
    expect($invoice->status)->toBe('paid')->and($invoice->amount_cents)->toBe(5000)->and($invoice->currency)->toBe('BRL');
    expect($invoice->tenant_id)->toBe($sub->tenant_id);
    expect($this->gateway()->charges())->toHaveCount(1)->and($this->gateway()->charges()[0]['amount'])->toBe(5000);
    expect($summary->charged)->toBe(1)->and($summary->suspended)->toBe(0);
});

it('CA-01 charges the price saved at signup, not the current plan price', function () {
    $sub = $this->subscription(['price_cents' => 1234]);
    DB::connection('pgsql_admin')->table('plans')->where('key', 'profissional')->update(['monthly_price_cents' => 9999]);

    runBilling();

    expect($this->invoicesOf($sub->id)[0]->amount_cents)->toBe(1234);
});

it('CA-02 gives a yearly subscription a one year period', function () {
    $sub = $this->subscription(['cycle' => 'yearly', 'price_cents' => 54000, 'trial_ends_at' => $this->at('2026-11-10 09:00:00')]);

    runBilling();

    expect(Carbon::parse($this->subscriptionRow($sub->id)->current_period_end)->toDateTimeString())->toBe('2027-11-10 09:00:00');
});

it('CA-03 leaves a trial that has not ended untouched', function () {
    $sub = $this->subscription(['trial_ends_at' => $this->at('2026-11-10 12:00:01')]);

    $summary = runBilling();

    expect($this->subscriptionRow($sub->id)->status)->toBe('trialing');
    expect($this->invoicesOf($sub->id))->toBe([])->and($this->gateway()->charges())->toBe([]);
    expect($summary->charged)->toBe(0);
});

it('CA-04 suspends right away when the payment is declined', function () {
    $sub = $this->subscription([], 'pm_fake_fail_x');

    $summary = runBilling();

    $row = $this->subscriptionRow($sub->id);
    expect($row->status)->toBe('suspended');
    expect(Carbon::parse($row->suspended_at)->toDateTimeString())->toBe('2026-11-10 12:00:00');
    expect($row->current_period_start)->toBeNull();

    $invoice = $this->invoicesOf($sub->id)[0];
    expect($invoice->status)->toBe('failed')->and($invoice->failure_reason)->toBe('card_declined');
    expect($summary->suspended)->toBe(1)->and($summary->charged)->toBe(0);
});

it('CA-04 does not retry a suspended subscription on the next run', function () {
    $sub = $this->subscription([], 'pm_fake_fail_x');
    runBilling();

    runBilling($this->at('2026-11-11 12:00:00'));

    expect($this->invoicesOf($sub->id))->toHaveCount(1)->and($this->gateway()->charges())->toHaveCount(1);
});

it('CA-05 never charges twice when the job runs again', function () {
    $sub = $this->subscription();

    runBilling();
    runBilling();
    runBilling($this->at('2026-11-10 13:00:00'));

    expect($this->invoicesOf($sub->id))->toHaveCount(1)->and($this->gateway()->charges())->toHaveCount(1);
});

it('CA-05 uses an idempotency key that survives a lost invoice row', function () {
    $sub = $this->subscription();
    runBilling();
    $firstKey = $this->gateway()->charges()[0]['key'];

    // Simula uma execução que cobrou mas perdeu a fatura: o mesmo período gera a mesma chave no gateway.
    DB::connection('pgsql_admin')->table('invoices')->delete();
    DB::connection('pgsql_admin')->table('subscriptions')->where('id', $sub->id)->update(['status' => 'trialing', 'current_period_start' => null, 'current_period_end' => null]);
    runBilling();

    expect($this->gateway()->charges())->toHaveCount(1);
    expect($firstKey)->toContain($sub->id);
});

it('CA-06 renews an active subscription continuing from the end of the previous period', function () {
    $sub = $this->subscription([
        'status' => 'active', 'trial_ends_at' => $this->at('2026-10-10 09:00:00'),
        'current_period_start' => $this->at('2026-10-10 09:00:00'), 'current_period_end' => $this->at('2026-11-10 09:00:00'),
    ]);

    runBilling();

    $row = $this->subscriptionRow($sub->id);
    expect(Carbon::parse($row->current_period_start)->toDateTimeString())->toBe('2026-11-10 09:00:00');
    expect(Carbon::parse($row->current_period_end)->toDateTimeString())->toBe('2026-12-10 09:00:00');
    expect($row->status)->toBe('active');
});

it('CA-06 does not renew before the period ends', function () {
    $sub = $this->subscription([
        'status' => 'active', 'current_period_start' => $this->at('2026-11-01 09:00:00'), 'current_period_end' => $this->at('2026-12-01 09:00:00'),
    ]);

    runBilling();

    expect($this->invoicesOf($sub->id))->toBe([]);
});

it('CA-06 suspends an active subscription when the renewal is declined', function () {
    $sub = $this->subscription([
        'status' => 'active', 'current_period_start' => $this->at('2026-10-10 09:00:00'), 'current_period_end' => $this->at('2026-11-10 09:00:00'),
    ], 'pm_fake_fail_x');

    runBilling();

    expect($this->subscriptionRow($sub->id)->status)->toBe('suspended');
});

it('CA-07 charges a single period when the system was down for more than a cycle', function () {
    $sub = $this->subscription([
        'status' => 'active', 'current_period_start' => $this->at('2026-07-01 09:00:00'), 'current_period_end' => $this->at('2026-08-01 09:00:00'),
    ]);

    runBilling();

    $row = $this->subscriptionRow($sub->id);
    expect(Carbon::parse($row->current_period_start)->toDateTimeString())->toBe('2026-11-10 12:00:00');
    expect(Carbon::parse($row->current_period_end)->toDateTimeString())->toBe('2026-12-10 12:00:00');
    expect($this->invoicesOf($sub->id))->toHaveCount(1);
});

it('CA-08 ends a monthly period in the last day of a shorter month without overflowing', function (string $due, string $expectedEnd) {
    $sub = $this->subscription(['trial_ends_at' => $this->at($due)]);

    runBilling(Carbon::parse($due, 'UTC')->addHour());

    expect(Carbon::parse($this->subscriptionRow($sub->id)->current_period_end)->toDateTimeString())->toBe($expectedEnd);
})->with([
    'janeiro 31 para fevereiro' => ['2026-01-31 09:00:00', '2026-02-28 09:00:00'],
    'ano bissexto' => ['2028-01-31 09:00:00', '2028-02-29 09:00:00'],
    'maio 31 para junho' => ['2026-05-31 09:00:00', '2026-06-30 09:00:00'],
]);

it('CA-09 does not call the gateway for a free subscription and keeps it active', function () {
    $sub = $this->subscription(['price_cents' => 0]);

    runBilling();

    expect($this->gateway()->charges())->toBe([]);
    expect($this->subscriptionRow($sub->id)->status)->toBe('active');
    $invoice = $this->invoicesOf($sub->id)[0];
    expect($invoice->status)->toBe('paid')->and($invoice->amount_cents)->toBe(0);
});

it('CA-11 ignores suspended and canceled subscriptions even when overdue', function (string $status) {
    $sub = $this->subscription(['status' => $status, 'current_period_end' => $this->at('2026-10-01 09:00:00'), 'trial_ends_at' => $this->at('2026-09-01 09:00:00')]);

    $summary = runBilling();

    expect($this->invoicesOf($sub->id))->toBe([])->and($this->subscriptionRow($sub->id)->status)->toBe($status);
    expect($summary->charged)->toBe(0)->and($summary->suspended)->toBe(0);
})->with(['suspended', 'canceled']);

it('CA-12 keeps the state and goes on with the others when the gateway fails technically', function () {
    $this->app->singleton(PaymentGateway::class, fn () => new class extends FakePaymentGateway
    {
        public function charge(string $customerRef, string $paymentMethodRef, int $amountCents, string $currency, string $idempotencyKey): ChargeResult
        {
            if (str_contains($paymentMethodRef, 'boom')) {
                throw new RuntimeException('gateway fora do ar');
            }

            return parent::charge($customerRef, $paymentMethodRef, $amountCents, $currency, $idempotencyKey);
        }
    });
    $broken = $this->subscription([], 'pm_fake_boom');
    $healthy = $this->subscription();

    $summary = runBilling();

    expect($this->subscriptionRow($broken->id)->status)->toBe('trialing');
    expect($this->invoicesOf($broken->id))->toBe([]);
    expect($this->subscriptionRow($healthy->id)->status)->toBe('active');
    expect($summary->errors)->toBe(1)->and($summary->charged)->toBe(1);
});

it('CA-13 suspends a subscription that has no payment method', function () {
    $sub = $this->subscription();
    DB::connection('pgsql_admin')->table('subscriptions')->where('id', $sub->id)->update(['payment_method_id' => null]);

    runBilling();

    expect($this->subscriptionRow($sub->id)->status)->toBe('suspended');
    expect($this->invoicesOf($sub->id)[0]->failure_reason)->toBe('no_payment_method');
    expect($this->gateway()->charges())->toBe([]);
});

it('CA-14 audits charges and suspensions without payment data', function () {
    $this->subscription();
    $this->subscription([], 'pm_fake_fail_x');

    runBilling();

    $events = DB::connection('pgsql_admin')->table('platform_audit_logs')->get();
    expect($events->pluck('event')->all())->toContain('billing.charged', 'billing.failed', 'subscription.suspended');
    expect($events->toJson())->not->toContain('pm_fake')->not->toContain('4242')->not->toContain('cus_fake');
});

it('never undoes a recorded charge when only the audit log fails', function () {
    $sub = $this->subscription();
    $this->app->bind(PlatformAudit::class, fn () => new class(request()) extends PlatformAudit
    {
        public function record(string $event, ?PlatformUser $user = null, array $metadata = []): void
        {
            throw new RuntimeException('auditoria fora do ar');
        }
    });

    $summary = runBilling();

    // A cobrança foi decidida e gravada; só o registro de auditoria falhou, e isso nunca desfaz a cobrança.
    expect($this->subscriptionRow($sub->id)->status)->toBe('active');
    expect($this->invoicesOf($sub->id))->toHaveCount(1);
    expect($summary->charged)->toBe(1);
});
