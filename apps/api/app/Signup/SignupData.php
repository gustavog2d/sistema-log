<?php

namespace App\Signup;

use Illuminate\Http\Request;

/** Dados do cadastro já validados, com tipos garantidos. */
final readonly class SignupData
{
    public function __construct(
        public string $businessName,
        public string $slug,
        public string $niche,
        public string $ownerName,
        public string $ownerEmail,
        public string $password,
        public string $plan,
        public string $cycle,
        public ?string $paymentMethodToken,
        public ?string $couponCode = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            businessName: $request->string('business_name')->toString(),
            slug: $request->string('slug')->toString(),
            niche: $request->string('niche')->toString(),
            ownerName: $request->string('owner_name')->toString(),
            ownerEmail: mb_strtolower($request->string('owner_email')->toString()),
            password: $request->string('password')->toString(),
            plan: $request->string('plan')->toString(),
            cycle: $request->string('cycle')->toString(),
            paymentMethodToken: $request->filled('payment_method_token') ? $request->string('payment_method_token')->toString() : null,
            couponCode: $request->filled('coupon_code') ? $request->string('coupon_code')->toString() : null,
        );
    }
}
