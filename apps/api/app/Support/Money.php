<?php

namespace App\Support;

/** Formatação de dinheiro e datas para texto de e-mail (dinheiro sempre em centavos). */
final class Money
{
    public static function brl(int $cents): string
    {
        return 'R$ '.number_format($cents / 100, 2, ',', '.');
    }
}
