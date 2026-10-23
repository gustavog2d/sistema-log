<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function createCoupon($test, string $token, array $data)
{
    return (fn () => $this->asToken($token, 'POST', 'coupons', $data))->call($test);
}

it('CA-01 lets the super admin create a coupon with the code in upper case', function () {
    $response = createCoupon($this, $this->adminToken(), ['code' => 'amigos-20', 'percent_off' => 20, 'max_redemptions' => 5, 'description' => 'Amigos'])->assertCreated();

    expect($response->json('code'))->toBe('AMIGOS-20')
        ->and($response->json('redeemed_count'))->toBe(0)
        ->and($response->json('status'))->toBe('active')
        ->and(DB::connection('pgsql_admin')->table('coupons')->value('code'))->toBe('AMIGOS-20');
});

it('CA-02 refuses invalid coupons', function (array $data) {
    makeCoupon(['code' => 'EXISTE']);

    createCoupon($this, $this->adminToken(), $data + ['code' => 'NOVO-1', 'percent_off' => 10])->assertUnprocessable();
})->with([
    'repeated code in another case' => [['code' => 'existe']],
    'percent zero' => [['percent_off' => 0]],
    'percent too high' => [['percent_off' => 101]],
    'limit zero' => [['max_redemptions' => 0]],
    'inverted validity' => [['valid_from' => '2026-12-31T00:00:00Z', 'valid_until' => '2026-12-01T00:00:00Z']],
    'short code' => [['code' => 'ab']],
    'strange characters' => [['code' => 'a b!']],
]);

it('CA-03 lets only the super admin write and the support team read', function () {
    $coupon = makeCoupon();

    // Sem login primeiro: depois de uma chamada autenticada o guard guarda o usuário em cache.
    $this->getJson('http://app.test/api/v1/platform/coupons')->assertUnauthorized();
    $this->postJson('http://app.test/api/v1/platform/coupons', ['code' => 'X-1', 'percent_off' => 10])->assertUnauthorized();

    $support = $this->supportToken();
    createCoupon($this, $support, ['code' => 'X-1', 'percent_off' => 10])->assertForbidden();
    $this->asToken($support, 'PATCH', "coupons/{$coupon->id}", ['status' => 'inactive'])->assertForbidden();
    $this->asToken($support, 'GET', 'coupons')->assertOk();
});

it('CA-04 does not let the limit drop below the uses already made, and allows the other changes', function () {
    $coupon = makeCoupon(['max_redemptions' => 5, 'redeemed_count' => 2]);
    $admin = $this->adminToken();

    $this->asToken($admin, 'PATCH', "coupons/{$coupon->id}", ['max_redemptions' => 1])->assertUnprocessable()->assertJsonValidationErrors('max_redemptions');
    $this->asToken($admin, 'PATCH', "coupons/{$coupon->id}", ['max_redemptions' => 2, 'status' => 'inactive', 'valid_until' => '2027-01-01T00:00:00Z'])
        ->assertOk()->assertJsonPath('status', 'inactive')->assertJsonPath('max_redemptions', 2);
});

it('CA-04 does not change the code or the percentage', function () {
    $coupon = makeCoupon();

    $this->asToken($this->adminToken(), 'PATCH', "coupons/{$coupon->id}", ['code' => 'OUTRO'])->assertUnprocessable();
    $this->asToken($this->adminToken(), 'PATCH', "coupons/{$coupon->id}", ['percent_off' => 50])->assertUnprocessable();
});

it('answers 404 when the coupon does not exist', function () {
    $this->asToken($this->adminToken(), 'PATCH', 'coupons/'.Str::uuid(), ['status' => 'inactive'])->assertNotFound();
});

it('CA-14 lists the uses and the limit', function () {
    makeCoupon(['code' => 'A-1', 'max_redemptions' => 3, 'redeemed_count' => 1]);
    makeCoupon(['code' => 'B-1']);

    $list = $this->asToken($this->adminToken(), 'GET', 'coupons')->assertOk()->json('data');

    expect(collect($list)->keyBy('code')->map(fn ($c) => [$c['redeemed_count'], $c['max_redemptions']])->all())->toBe(['A-1' => [1, 3], 'B-1' => [0, null]]);
});

it('CA-13 audits the creation and the change', function () {
    $coupon = createCoupon($this, $this->adminToken(), ['code' => 'AUD-1', 'percent_off' => 10])->assertCreated();
    $this->asToken($this->adminToken(), 'PATCH', 'coupons/'.$coupon->json('id'), ['status' => 'inactive'])->assertOk();

    $events = DB::connection('pgsql_admin')->table('platform_audit_logs')->pluck('event')->all();
    expect($events)->toContain('coupon.created')->toContain('coupon.updated');
});
