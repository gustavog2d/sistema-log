<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

function methodRefOf(object $workspace): string
{
    return (string) DB::connection('pgsql_admin')->table('payment_methods')
        ->where('id', subscriptionOf($workspace)->payment_method_id)->value('gateway_ref');
}

it('CA-02 swaps the payment method of an active subscription without charging', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'active');
    $before = subscriptionOf($ws);

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'k1')
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('payment_method.last4', '4242');

    expect(subscriptionOf($ws)->payment_method_id)->not->toBe($before->payment_method_id)
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0);
});

it('CA-02 also swaps during the trial and keeps the trial dates', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'trialing', ['trial_ends_at' => now()->addDays(3)]);
    $before = subscriptionOf($ws);

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_fail_on_charge'], 'k1')->assertOk();

    expect(subscriptionOf($ws)->trial_ends_at)->toBe($before->trial_ends_at)
        ->and(methodRefOf($ws))->toStartWith('pm_fake_fail_');
});

it('CA-03 charges a suspended subscription now and gives access back', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'suspended', ['suspended_at' => now()->subDay(), 'current_period_end' => now()->subDay()]);

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'k1')
        ->assertOk()
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('can_write', true);

    $row = subscriptionOf($ws);
    $invoices = DB::connection('pgsql_admin')->table('invoices')->where('tenant_id', $ws->tenant_id)->get();

    expect($row->status)->toBe('active')
        ->and($row->suspended_at)->toBeNull()
        ->and($row->current_period_end)->toBeGreaterThan(now()->toDateTimeString())
        ->and($invoices)->toHaveCount(1)
        ->and($invoices[0]->status)->toBe('paid')
        ->and($invoices[0]->amount_cents)->toBe(5000);

    // A escrita volta na hora (usa a própria rota de cancelamento como rota de escrita).
    manage($this, $token, 'POST', 'neg', 'subscription/cancel')->assertOk();
});

it('CA-04 keeps the subscription suspended and the old method when the new charge is refused', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'suspended', ['suspended_at' => now()->subDay()]);
    $before = subscriptionOf($ws);

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_fail_on_charge'], 'k1')
        ->assertStatus(402)
        ->assertJsonPath('code', 'payment_failed');

    expect(subscriptionOf($ws)->status)->toBe('suspended')
        ->and(subscriptionOf($ws)->payment_method_id)->toBe($before->payment_method_id)
        ->and(DB::connection('pgsql_admin')->table('invoices')->count())->toBe(0);
});

it('CA-05 refuses a declined token and changes nothing', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'active');
    $before = subscriptionOf($ws);

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_declined'], 'k1')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('payment_method_token');

    expect(subscriptionOf($ws)->payment_method_id)->toBe($before->payment_method_id);
});

it('CA-06 refuses to change the method of a canceled subscription', function () {
    [, $token] = ownerOf($this, 'neg', 'canceled');

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'k1')->assertStatus(409);
});

it('CA-07 charges only once when the same Idempotency-Key is repeated', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'suspended', ['suspended_at' => now()->subDay()]);

    $first = manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'mesma');
    $second = manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'mesma');

    $first->assertOk();
    $second->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    expect(DB::connection('pgsql_admin')->table('invoices')->where('tenant_id', $ws->tenant_id)->count())->toBe(1);
});

it('requires the Idempotency-Key and the token', function () {
    [, $token] = ownerOf($this, 'neg', 'active');

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'])->assertUnprocessable();
    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', [], 'k2')->assertUnprocessable()->assertJsonValidationErrors('payment_method_token');
});

it('CA-03 reactivates a courtesy subscription (price zero) without charging', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'suspended', ['suspended_at' => now()->subDay(), 'price_cents' => 0]);

    manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'k1')->assertOk()->assertJsonPath('status', 'active');

    $invoice = DB::connection('pgsql_admin')->table('invoices')->where('tenant_id', $ws->tenant_id)->first();
    expect($invoice->amount_cents)->toBe(0)->and($invoice->status)->toBe('paid');
});

it('CA-02 audits the change and never exposes the gateway reference', function () {
    [, $token] = ownerOf($this, 'neg', 'active');

    $response = manage($this, $token, 'PUT', 'neg', 'subscription/payment-method', ['payment_method_token' => 'tok_ok'], 'k1')->assertOk();

    expect(json_encode($response->json()))->not->toContain('pm_fake')
        ->and(DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'subscription.payment_method_changed')->count())->toBe(1);
});
