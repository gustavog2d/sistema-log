<?php

namespace App\Http\Controllers;

use App\Coupons\CouponService;
use App\Models\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Mostra o preço final com um cupom, sem consumi-lo (a tela do cadastro usa antes de confirmar). */
class CouponCheckController extends Controller
{
    public function __invoke(Request $request, CouponService $coupons): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'plan' => ['required', 'string', Rule::exists('plans', 'key')->where('status', 'active')],
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);

        $plan = Plan::query()->where('key', $request->string('plan')->toString())->where('status', 'active')->firstOrFail();
        $quote = $coupons->quote($plan, $request->string('cycle')->toString(), $request->string('code')->toString(), 'code');

        return response()->json([
            'percent_off' => $quote['coupon'] === null ? 0 : CouponService::percentOf($quote['coupon']),
            'price_cents' => $quote['price'], 'final_price_cents' => $quote['final'],
            'currency' => $plan->currency, 'requires_payment_method' => ! $quote['courtesy'],
        ]);
    }
}
