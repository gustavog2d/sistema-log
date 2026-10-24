<?php

namespace App\Payments;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Uma regra dos pagamentos foi quebrada: devolve o status certo, o `code` e, se for o caso, o erro por campo e o saldo. */
class PaymentRuleViolation extends RuntimeException
{
    /** @param  array<string, mixed>  $extra  */
    public function __construct(public readonly int $status, public readonly string $problemCode, string $message, private readonly array $extra = [], private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        $extra = ['code' => $this->problemCode] + $this->extra;

        if ($this->field !== null) {
            $extra['errors'] = [$this->field => [$this->getMessage()]];
        }

        return Problem::response($this->status, $this->status === 409 ? 'Conflict' : 'Unprocessable Entity', $this->getMessage(), $extra);
    }
}
