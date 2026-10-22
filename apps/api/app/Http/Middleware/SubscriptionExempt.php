<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marca, de propósito, uma rota do assinante que funciona mesmo com a assinatura suspensa ou cancelada
 * (login, recuperação de senha, 2FA, saída). Um teste lista todas as rotas marcadas.
 */
class SubscriptionExempt
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
