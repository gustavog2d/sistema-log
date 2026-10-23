<?php

namespace App\Services;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A categoria tem serviços e não pode ser excluída. */
class CategoryInUse extends RuntimeException
{
    public function __construct(public readonly int $count)
    {
        parent::__construct('Esta categoria tem serviços. Mova ou apague os serviços antes.');
    }

    public function render(): JsonResponse
    {
        return Problem::response(409, 'Conflict', $this->getMessage(), ['code' => 'category_in_use', 'count' => $this->count]);
    }
}
