<?php

namespace App\Expenses;

use Closure;

/** Normaliza e valida os campos de categorias e despesas (FIN-002). */
final class ExpenseInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                // Linha única: quebras, tabulações e espaços repetidos viram um espaço; as pontas saem.
                $input[$field] = trim((string) preg_replace('/\s+/u', ' ', $input[$field]));
            }
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    public static function categoryRules(?string $ignoreId = null): array
    {
        return ['name' => ['required', 'string', 'min:2', 'max:60', self::singleLine('O nome não pode ter caracteres de controle.'), self::uniqueName($ignoreId)]];
    }

    /** @return array<string, list<mixed>> */
    public static function expenseRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'category_id' => [$required, 'bail', 'uuid', self::categoryExists()],
            'description' => [$required, 'string', 'min:2', 'max:255', self::singleLine('A descrição não pode ter caracteres de controle.')],
            'amount_cents' => [$required, 'integer', 'between:1,100000000'],
            'spent_on' => [$required, 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
        ];
    }

    /** Texto de uma linha sem caracteres de controle nem de direção do texto (o resto, como "@" e endereços, vale). */
    private static function singleLine(string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($message): void {
            if (is_string($value) && (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', $value) === 1)) {
                $fail($message);
            }
        };
    }

    /** Nome único no negócio sem diferenciar maiúsculas e acentos (o banco decide em uma corrida). */
    private static function uniqueName(?string $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $query = ExpenseCategory::query()->whereRaw('name_key = lower(f_unaccent(?))', [$value]);

            if ($ignoreId !== null) {
                $query->whereKeyNot($ignoreId);
            }

            if ($query->exists()) {
                $fail('Já existe uma categoria com este nome.');
            }
        };
    }

    private static function categoryExists(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! ExpenseCategory::query()->whereKey($value)->exists()) {
                $fail('A categoria não existe.');
            }
        };
    }
}
