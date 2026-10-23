<?php

namespace App\Billing;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** O uso atual do negócio passa de um limite do plano novo: a troca não acontece. */
class PlanLimitExceeded extends RuntimeException
{
    /** @param  list<array{feature: string, limit: int, usage: int}>  $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('O uso atual passa do limite do plano escolhido.');
    }

    public function render(): JsonResponse
    {
        return Problem::response(409, 'Conflict', $this->getMessage(), ['code' => 'plan_limit_exceeded', 'violations' => $this->violations]);
    }
}
