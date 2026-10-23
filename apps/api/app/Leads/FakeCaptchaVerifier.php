<?php

namespace App\Leads;

/** Verificador de teste: aprova só o token `captcha_ok`. Só existe em desenvolvimento e testes. */
class FakeCaptchaVerifier implements CaptchaVerifier
{
    public const TOKEN = 'captcha_ok';

    public function verify(string $token, ?string $ip, ?string $action = null): bool
    {
        return hash_equals(self::TOKEN, $token);
    }
}
