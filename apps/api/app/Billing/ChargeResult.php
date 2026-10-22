<?php

namespace App\Billing;

final readonly class ChargeResult
{
    public function __construct(
        public bool $paid,
        public string $ref,
        public ?string $failureReason = null,
    ) {}
}
