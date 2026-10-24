<?php

namespace App\Payments;

use Closure;
use Illuminate\Support\Carbon;

/** Validação dos campos de um pagamento (FIN-001). As regras do conjunto (limite, situação) ficam em `PaymentLedger`. */
final class PaymentInput
{
    public const TYPES = ['deposit', 'installment', 'final'];

    public const METHODS = ['cash', 'pix', 'credit_card', 'debit_card', 'other'];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        // O instante entra sempre em UTC: o fuso do texto não pode se perder na gravação.
        if (array_key_exists('paid_at', $input) && is_string($input['paid_at']) && strtotime($input['paid_at']) !== false) {
            $input['paid_at'] = Carbon::parse($input['paid_at'])->utc()->format('Y-m-d H:i:s');
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    public static function createRules(): array
    {
        return [
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'method' => ['required', 'in:'.implode(',', self::METHODS)],
            'amount_cents' => ['required', 'integer', 'between:1,100000000'],
            'paid_at' => ['sometimes', 'date', 'after:2000-01-01', self::notFuture()],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function patchRules(): array
    {
        return [
            'type' => ['sometimes', 'in:'.implode(',', self::TYPES)],
            'method' => ['sometimes', 'in:'.implode(',', self::METHODS)],
            'paid_at' => ['sometimes', 'date', 'after:2000-01-01', self::notFuture()],
        ];
    }

    /** Até 5 minutos no futuro (relógios que não batem); mais que isso é cobrança futura, não pagamento. */
    private static function notFuture(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strtotime($value) !== false && Carbon::parse($value, 'UTC')->greaterThan(now()->addMinutes(5))) {
                $fail('A data do pagamento não pode estar no futuro.');
            }
        };
    }
}
