<?php

namespace App\Plans;

final class YearlyPrice
{
    /** Doze mensalidades com o desconto anual, em centavos inteiros; nulo se faltar preço ou desconto. */
    public static function cents(?int $monthlyCents, ?int $discountPercent): ?int
    {
        if ($monthlyCents === null || $discountPercent === null) {
            return null;
        }

        return (int) round($monthlyCents * 12 * (100 - $discountPercent) / 100);
    }
}
