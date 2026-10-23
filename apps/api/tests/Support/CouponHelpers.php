<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cria um cupom direto no banco (papel dono). */
function makeCoupon(array $overrides = []): object
{
    $row = $overrides + [
        'id' => (string) Str::uuid(), 'code' => 'PROMO20', 'percent_off' => 20, 'status' => 'active',
        'max_redemptions' => null, 'redeemed_count' => 0, 'valid_from' => null, 'valid_until' => null,
        'description' => null, 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::connection('pgsql_admin')->table('coupons')->insert($row);

    return (object) $row;
}

function couponUses(string $code): int
{
    return (int) DB::connection('pgsql_admin')->table('coupons')->where('code', $code)->value('redeemed_count');
}
