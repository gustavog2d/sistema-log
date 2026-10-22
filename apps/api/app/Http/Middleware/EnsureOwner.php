<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\TenantAuth\AuthenticatedPerson;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Só a dona do negócio passa (depois de `auth.tenant`). */
class EnsureOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $person = $request->attributes->get('tenant_person');

        if (! $person instanceof AuthenticatedPerson || $person->role !== 'owner') {
            return Problem::response(403, 'Forbidden', 'Apenas a dona do negócio pode fazer isto.');
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
