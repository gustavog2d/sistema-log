<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

class TenantContextMissing extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("Consulta a {$model} sem tenant em contexto.");
    }
}
