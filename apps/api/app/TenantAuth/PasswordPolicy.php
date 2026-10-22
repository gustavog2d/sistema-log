<?php

namespace App\TenantAuth;

use Closure;
use Illuminate\Support\Facades\Config;

/** Regras de senha: mínimo de caracteres e no máximo 72 bytes (o bcrypt ignora o que passa disso). */
final class PasswordPolicy
{
    /** @return list<mixed> */
    public static function rules(): array
    {
        return [
            'required', 'string', 'min:'.Config::integer('platform.min_password_length'),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && strlen($value) > 72) {
                    $fail('A senha pode ter até 72 bytes.');
                }
            },
        ];
    }
}
