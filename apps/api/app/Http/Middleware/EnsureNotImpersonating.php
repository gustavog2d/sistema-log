<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\TenantAuth\AuthenticatedPerson;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Acesso de suporte (PLT-009) não mexe em senha, 2FA nem na assinatura: essas rotas são só da própria pessoa. */
class EnsureNotImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        $person = $request->attributes->get('tenant_person');

        if ($person instanceof AuthenticatedPerson && $person->impersonatedBy !== null) {
            return Problem::response(403, 'Forbidden', 'O acesso de suporte não pode fazer isto.', ['code' => 'impersonation_forbidden']);
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
