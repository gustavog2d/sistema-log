<?php

namespace App\Leads;

/** Confere se o pedido veio de uma pessoa (anti-robô). Falha ou dúvida contam como recusa. */
interface CaptchaVerifier
{
    /** `$action` é o nome da ação que a página declarou ao pedir o token (o Google a devolve na resposta). */
    public function verify(string $token, ?string $ip, ?string $action = null): bool;
}
