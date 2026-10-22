<?php

namespace App\Tenancy\Exceptions;

use RuntimeException;

class TenantMismatch extends RuntimeException
{
    public static function forModel(string $model): self
    {
        return new self("{$model} recebeu um tenant_id diferente do tenant em contexto.");
    }
}
