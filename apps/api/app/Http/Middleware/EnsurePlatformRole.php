<?php

namespace App\Http\Middleware;

use App\Models\PlatformUser;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Restringe a rota a um ou mais papéis da equipe da plataforma (`platform.role:super_admin`). */
class EnsurePlatformRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user instanceof PlatformUser || ! in_array($user->role, $roles, true)) {
            throw new AuthorizationException('Acesso negado.');
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
