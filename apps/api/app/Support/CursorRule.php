<?php

namespace App\Support;

use Closure;

/**
 * Valida o cursor de paginação: ele é devolvido pela própria página (`next_cursor`), então só o formato que ela gera
 * é aceito. Um cursor adulterado ou de outra lista vira 422 em vez de erro do banco.
 */
final class CursorRule
{
    /**
     * @param  array<string, 'uuid'|'date'>  $columns  colunas de ordenação do cursor e o tipo de cada uma
     */
    public static function for(array $columns): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($columns): void {
            $decoded = is_string($value) ? json_decode((string) base64_decode(strtr($value, '-_', '+/'), true), true) : null;
            $valid = is_array($decoded) && is_bool($decoded['_pointsToNextItems'] ?? null);

            foreach ($columns as $column => $type) {
                $item = is_array($decoded) ? ($decoded[$column] ?? null) : null;
                $valid = $valid && is_string($item) && match ($type) {
                    'uuid' => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $item) === 1,
                    'date' => strtotime($item) !== false,
                };
            }

            if (! $valid) {
                $fail('O cursor é inválido.');
            }
        };
    }
}
