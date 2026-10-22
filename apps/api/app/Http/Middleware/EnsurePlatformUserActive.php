<?php

namespace App\Http\Middleware;

use App\Models\PlatformUser;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Recusa tokens de usuários desativados, mesmo que ainda existam. */
class EnsurePlatformUserActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof PlatformUser || ! $user->active) {
            throw new AuthenticationException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
