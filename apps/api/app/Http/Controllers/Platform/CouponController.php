<?php

namespace App\Http\Controllers\Platform;

use App\Coupons\CouponService;
use App\Http\Controllers\Controller;
use App\Platform\Auth\AuthenticatedPlatformUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    public function index(CouponService $coupons): JsonResponse
    {
        return response()->json(['data' => $coupons->list()]);
    }

    public function store(Request $request, CouponService $coupons): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'code' => ['required', 'string', 'min:6', 'max:40', 'regex:/^[A-Za-z0-9-]+$/'],
            'percent_off' => ['required', 'integer', 'between:1,100'],
            // Cortesia (100%) sempre com teto de usos: um código vazado não pode abrir contas sem limite.
            'max_redemptions' => [Rule::requiredIf($request->integer('percent_off') === 100), 'nullable', 'integer', 'min:1', 'max:1000000'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after:valid_from'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        return response()->json($coupons->create(AuthenticatedPlatformUser::from($request), $data), 201);
    }

    public function update(Request $request, string $id, CouponService $coupons): JsonResponse
    {
        // O código e o percentual identificam o que foi prometido a quem já usou: não mudam.
        /** @var array<string, mixed> $data */
        $data = $request->validate([
            'code' => ['prohibited'],
            'percent_off' => ['prohibited'],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'max_redemptions' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date'],
            'description' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        return response()->json($coupons->update(AuthenticatedPlatformUser::from($request), $id, $data));
    }
}
