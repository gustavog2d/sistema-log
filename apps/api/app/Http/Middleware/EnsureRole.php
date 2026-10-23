<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\TenantAuth\AuthenticatedPerson;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Só os papéis listados passam (depois de `auth.tenant`): `role:owner,manager`. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $person = $request->attributes->get('tenant_person');

        if (! $person instanceof AuthenticatedPerson || ! in_array($person->role, $roles, true)) {
            return Problem::response(403, 'Forbidden', 'Seu papel não permite esta ação.');
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
