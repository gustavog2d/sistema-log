<?php

namespace App\Support;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** O cadastro é usado por outros registros e não pode ser excluído (cliente, serviço, profissional com agendamentos). */
class ResourceInUse extends RuntimeException
{
    public function __construct(public readonly string $problemCode, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return Problem::response(409, 'Conflict', $this->getMessage(), ['code' => $this->problemCode]);
    }
}
