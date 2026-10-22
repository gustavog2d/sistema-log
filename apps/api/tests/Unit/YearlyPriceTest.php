<?php

use App\Plans\YearlyPrice;

it('CA-02 applies the yearly discount to twelve months', function () {
    expect(YearlyPrice::cents(10000, 20))->toBe(96000);
});

it('CA-02 rounds to whole cents', function () {
    expect(YearlyPrice::cents(1999, 15))->toBe((int) round(1999 * 12 * 85 / 100));
});

it('CA-02 accepts a zero and a full discount', function () {
    expect(YearlyPrice::cents(10000, 0))->toBe(120000)->and(YearlyPrice::cents(10000, 100))->toBe(0);
});

it('CA-03 returns null when the price or the discount is missing', function (?int $monthly, ?int $discount) {
    expect(YearlyPrice::cents($monthly, $discount))->toBeNull();
})->with([[null, 20], [10000, null], [null, null]]);
