<?php

namespace App\Payments;

/** Contas do financeiro de um agendamento (FIN-001): situação do pagamento e comissão. */
final class AppointmentFinance
{
    public static function status(?int $total, int $received): string
    {
        if ($total === null || $total <= 0) {
            return 'no_value';
        }

        if ($received <= 0) {
            return 'unpaid';
        }

        return $received >= $total ? 'paid' : 'partial';
    }

    /** Comissão em centavos: valor combinado × percentual, arredondado ao centavo (meio para cima). */
    public static function commissionCents(?int $total, int $percent): int
    {
        return $total === null ? 0 : (int) round($total * $percent / 100, 0, PHP_ROUND_HALF_UP);
    }
}
