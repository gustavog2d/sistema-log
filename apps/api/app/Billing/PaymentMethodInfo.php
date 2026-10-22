<?php

namespace App\Billing;

/** Meio de pagamento já aceito pelo gateway: só referência, bandeira e final (nunca o número). */
final readonly class PaymentMethodInfo
{
    public function __construct(
        public string $ref,
        public string $type,
        public string $brand,
        public string $last4,
    ) {}
}
