<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Tenancy\SubdomainTenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rotas do grupo `platform`: só nos hosts da plataforma, nunca no subdomínio de um tenant. */
class PlatformOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array(strtolower($request->getHost()), SubdomainTenantResolver::platformHosts(), true)) {
            return Problem::response(404, 'Not Found');
        }

        /** @var Response $response */
        $response = $next($request);
        // Respostas com segredos (TOTP, códigos de recuperação, tokens) nunca ficam em cache.
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
