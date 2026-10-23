<?php

use App\Billing\PaymentGateway;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedCatalog();
});

it('CA-05 freezes the discounted price, counts the use and records the redemption', function () {
    makeCoupon(['code' => 'PROMO20', 'percent_off' => 20]);

    $response = $this->signup(['coupon_code' => 'promo20'])->assertCreated();

    $subscription = DB::connection('pgsql_admin')->table('subscriptions')->first();
    $redemption = DB::connection('pgsql_admin')->table('coupon_redemptions')->first();

    expect($response->json('subscription.price_cents'))->toBe(4000)
        ->and($response->json('coupon'))->toBe(['code' => 'PROMO20', 'percent_off' => 20])
        ->and($subscription->price_cents)->toBe(4000)
        ->and(couponUses('PROMO20'))->toBe(1)
        ->and([$redemption->original_price_cents, $redemption->final_price_cents, $redemption->percent_off])->toBe([5000, 4000, 20])
        ->and($redemption->subscription_id)->toBe($subscription->id);
});

it('CA-05 rounds the discounted price down to the cent and applies to the yearly price', function () {
    makeCoupon(['code' => 'TRES', 'percent_off' => 33]);

    $price = $this->signup(['coupon_code' => 'TRES', 'cycle' => 'yearly'])->assertCreated()->json('subscription.price_cents');
    $full = DB::connection('pgsql_admin')->table('coupon_redemptions')->value('original_price_cents');

    expect($price)->toBe(intdiv($full * 67, 100));
});

it('CA-06 answers the same 422 for a coupon that is unknown, inactive, expired, not started or exhausted, and consumes nothing', function () {
    makeCoupon(['code' => 'INATIVO', 'status' => 'inactive']);
    makeCoupon(['code' => 'VENCIDO', 'valid_until' => now()->subDay()]);
    makeCoupon(['code' => 'FUTURO', 'valid_from' => now()->addDay()]);
    makeCoupon(['code' => 'ESGOTADO', 'max_redemptions' => 1, 'redeemed_count' => 1]);

    $answers = collect(['NAOEXISTE', 'INATIVO', 'VENCIDO', 'FUTURO', 'ESGOTADO'])
        ->map(fn ($code) => $this->signup(['coupon_code' => $code])->assertUnprocessable()->assertJsonValidationErrors('coupon_code')->json('errors.coupon_code'));

    expect($answers->unique()->count())->toBe(1);
    $this->nothingWasCreated();
    expect(couponUses('ESGOTADO'))->toBe(1);
});

it('CA-07 creates a courtesy account without a card and without a customer in the gateway', function () {
    makeCoupon(['code' => 'CORTESIA', 'percent_off' => 100]);

    $response = $this->signup(['coupon_code' => 'CORTESIA', 'payment_method_token' => null, 'owner_email' => 'cortesia@exemplo.com'])->assertCreated();
    $subscription = DB::connection('pgsql_admin')->table('subscriptions')->first();

    expect($response->json('subscription.price_cents'))->toBe(0)
        ->and($response->json('payment_method'))->toBeNull()
        ->and($subscription->payment_method_id)->toBeNull()
        ->and($subscription->gateway_customer_ref)->toBeNull()
        ->and($subscription->status)->toBe('trialing')
        ->and(DB::connection('pgsql_admin')->table('payment_methods')->count())->toBe(0);
});

it('CA-07 also lets a courtesy account come with a card, which is kept', function () {
    makeCoupon(['code' => 'CORTESIA', 'percent_off' => 100]);

    $this->signup(['coupon_code' => 'CORTESIA'])->assertCreated()->assertJsonPath('payment_method.last4', '4242');
});

it('CA-08 still asks for the card when the price is above zero', function () {
    makeCoupon(['code' => 'PROMO20']);

    $this->signup(['coupon_code' => 'PROMO20', 'payment_method_token' => null])->assertUnprocessable()->assertJsonValidationErrors('payment_method_token');
    $this->signup(['payment_method_token' => null])->assertUnprocessable()->assertJsonValidationErrors('payment_method_token');
    $this->nothingWasCreated();
    expect(couponUses('PROMO20'))->toBe(0);
});

it('CA-10 does not consume the coupon when the signup fails', function () {
    makeCoupon(['code' => 'PROMO20']);

    $this->signup(['coupon_code' => 'PROMO20', 'payment_method_token' => 'tok_declined'])->assertUnprocessable();
    $this->signup(['coupon_code' => 'PROMO20', 'slug' => 'admin'])->assertUnprocessable();

    expect(couponUses('PROMO20'))->toBe(0);
    $this->nothingWasCreated();
});

it('CA-10 does not consume the coupon when the email is already taken', function () {
    makeCoupon(['code' => 'PROMO20']);
    $this->signup()->assertCreated();

    $this->signup(['coupon_code' => 'PROMO20', 'slug' => 'outroslug'], 'outra-chave')->assertUnprocessable()->assertJsonValidationErrors('owner_email');

    expect(couponUses('PROMO20'))->toBe(0);
});

it('CA-12 gives the last use to one signup only', function () {
    makeCoupon(['code' => 'ULTIMO', 'max_redemptions' => 1]);

    $this->signup(['coupon_code' => 'ULTIMO'])->assertCreated();
    $this->signup(['coupon_code' => 'ULTIMO', 'slug' => 'segundo', 'owner_email' => 'segundo@exemplo.com'], 'outra-chave')->assertUnprocessable()->assertJsonValidationErrors('coupon_code');

    expect(couponUses('ULTIMO'))->toBe(1)->and(DB::connection('pgsql_admin')->table('tenants')->count())->toBe(1);
});

it('CA-13 audits the redemption without personal data', function () {
    makeCoupon(['code' => 'PROMO20']);
    $this->signup(['coupon_code' => 'PROMO20'])->assertCreated();

    $log = DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'coupon.redeemed')->first();

    expect($log)->not->toBeNull()->and($log->metadata)->not->toContain('ana@exemplo.com')->not->toContain('tok_ok');
});

it('does not create a gateway customer for a coupon that is refused', function () {
    $gateway = app(PaymentGateway::class);
    $before = count($gateway->activeCustomers());

    $this->signup(['coupon_code' => 'NAOEXISTE'])->assertUnprocessable();

    expect(count($gateway->activeCustomers()))->toBe($before);
});
