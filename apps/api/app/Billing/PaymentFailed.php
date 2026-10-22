<?php

namespace App\Billing;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A cobrança da regularização foi recusada: a assinatura continua suspensa. */
class PaymentFailed extends RuntimeException
{
    public function render(): JsonResponse
    {
        return Problem::response(402, 'Payment Required', 'O pagamento foi recusado. Tente outro meio de pagamento.', ['code' => 'payment_failed']);
    }
}
