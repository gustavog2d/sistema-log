<?php

namespace App\Billing;

/** Resultado de uma execução de cobrança. */
final class BillingRunSummary
{
    public int $charged = 0;

    public int $suspended = 0;

    public int $errors = 0;
}
