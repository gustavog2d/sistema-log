<?php

namespace App\Clients;

use Closure;
use Illuminate\Support\Str;

/** Normaliza e valida os campos de um cliente (CLI-001): telefone E.164, e-mail em minúsculas, Instagram sem `@`. */
final class ClientInput
{
    /**
     * Normalização antes da validação: só mexe nos campos enviados.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['name', 'email', 'instagram', 'notes'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]);
            }
        }

        if (array_key_exists('phone', $input) && is_string($input['phone'])) {
            // Espaços, hífens, pontos e parênteses saem; o `+` e os dígitos ficam.
            $input['phone'] = preg_replace('/[\s().\-]/', '', $input['phone']);
        }

        if (array_key_exists('email', $input) && is_string($input['email'])) {
            $input['email'] = $input['email'] === '' ? null : mb_strtolower($input['email']);
        }

        if (array_key_exists('instagram', $input) && is_string($input['instagram'])) {
            $handle = Str::lower(ltrim($input['instagram'], '@'));
            $input['instagram'] = $handle === '' ? null : $handle;
        }

        if (array_key_exists('notes', $input) && $input['notes'] === '') {
            $input['notes'] = null;
        }

        return $input;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $partial, ?string $ignoreId = null, bool $unique = true): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $only = fn (string $column, string $message): array => $unique ? [self::unique($column, $ignoreId, $message)] : [];

        return [
            'name' => [$required, 'string', 'min:2', 'max:100', self::plainText()],
            'phone' => [$required, 'string', 'regex:/^\+[1-9][0-9]{7,14}$/', ...$only('phone', 'Já existe um cliente com este telefone.')],
            // Só ASCII: letras de outros alfabetos poderiam imitar e-mails de outras pessoas.
            'email' => ['sometimes', 'nullable', 'string', 'email:rfc,strict', 'max:255', 'regex:/^[\x21-\x7E]+$/', ...$only('email', 'Já existe um cliente com este e-mail.')],
            'instagram' => ['sometimes', 'nullable', 'string', 'regex:/^[a-z0-9._]{1,30}$/', ...$only('instagram', 'Já existe um cliente com este Instagram.')],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** Nomes vão em telas e e-mails: sem caracteres de controle, endereços de internet nem de e-mail. */
    public static function plainText(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || preg_match('~https?:|www\.|://|@~i', $value) === 1)) {
                $fail('Use só o nome, sem endereços nem caracteres especiais de controle.');
            }
        };
    }

    /** Único no negócio (o RLS e o filtro do tenant já limitam a consulta; o banco decide em uma corrida). */
    private static function unique(string $column, ?string $ignoreId, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($column, $ignoreId, $message): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $query = Client::query()->where($column, $value);

            if ($ignoreId !== null) {
                $query->whereKeyNot($ignoreId);
            }

            if ($query->exists()) {
                $fail($message);
            }
        };
    }
}
