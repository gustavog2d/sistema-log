<?php

beforeEach(function () {
    $this->seedCatalog();
});

function check($test, array $data)
{
    return $test->postJson('http://app.test/api/v1/signup/coupon-check', $data);
}

it('CA-11 shows the final price without consuming the coupon', function () {
    makeCoupon(['code' => 'PROMO20', 'percent_off' => 20]);

    check($this, ['code' => 'promo20', 'plan' => 'profissional', 'cycle' => 'monthly'])
        ->assertOk()
        ->assertExactJson(['percent_off' => 20, 'price_cents' => 5000, 'final_price_cents' => 4000, 'currency' => 'BRL', 'requires_payment_method' => true]);

    expect(couponUses('PROMO20'))->toBe(0);
});

it('CA-11 says a full discount does not need a payment method', function () {
    makeCoupon(['code' => 'CORTESIA', 'percent_off' => 100]);

    check($this, ['code' => 'CORTESIA', 'plan' => 'estudio', 'cycle' => 'yearly'])->assertOk()->assertJsonPath('final_price_cents', 0)->assertJsonPath('requires_payment_method', false);
});

it('CA-11 gives the same 422 for any unavailable coupon', function () {
    makeCoupon(['code' => 'INATIVO', 'status' => 'inactive']);
    makeCoupon(['code' => 'ESGOTADO', 'max_redemptions' => 1, 'redeemed_count' => 1]);

    $answers = collect(['NAOEXISTE', 'INATIVO', 'ESGOTADO'])->map(fn ($code) => check($this, ['code' => $code, 'plan' => 'profissional', 'cycle' => 'monthly'])->assertUnprocessable()->json('errors.code'));

    expect($answers->unique()->count())->toBe(1);
});

it('validates the plan and the cycle and limits the attempts', function () {
    makeCoupon();

    check($this, ['code' => 'PROMO20', 'plan' => 'inexistente', 'cycle' => 'monthly'])->assertUnprocessable();
    check($this, ['code' => 'PROMO20', 'plan' => 'profissional', 'cycle' => 'semanal'])->assertUnprocessable();

    $last = null;
    foreach (range(1, 40) as $i) {
        $last = check($this, ['code' => 'CHUTE'.$i, 'plan' => 'profissional', 'cycle' => 'monthly'])->status();
    }

    expect($last)->toBe(429);
});

it('is not available on a business host', function () {
    $this->postJson('http://neg.app.test/api/v1/signup/coupon-check', ['code' => 'X', 'plan' => 'profissional', 'cycle' => 'monthly'])->assertNotFound();
});
