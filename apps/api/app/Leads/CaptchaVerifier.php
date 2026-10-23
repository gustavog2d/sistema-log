<?php

namespace App\Leads;

/** Confere se o pedido veio de uma pessoa (anti-robô). Falha ou dúvida contam como recusa. */
interface CaptchaVerifier
{
    public function verify(string $token, ?string $ip): bool;
}
