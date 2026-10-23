<?php

namespace App\Team;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A ação não cabe no estado da equipe (já é membro, limite do plano, ...). */
class TeamConflict extends RuntimeException
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
