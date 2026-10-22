<?php

namespace App\Billing;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A ação não cabe no estado atual da assinatura (ex.: trocar o cartão de uma assinatura cancelada). */
class SubscriptionConflict extends RuntimeException
{
    public function __construct(string $message, public readonly string $problemCode)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return Problem::response(409, 'Conflict', $this->getMessage(), ['code' => $this->problemCode]);
    }
}
