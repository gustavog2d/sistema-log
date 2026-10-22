<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\Auth\AccessTokens;
use App\Platform\Auth\AuthenticatedPlatformUser;
use App\Platform\Auth\ChallengeCompletion;
use App\Platform\Auth\LoginGuard;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function login(Request $request, LoginGuard $guard, AccessTokens $tokens): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        return response()->json($tokens->challenge(
            $guard->attempt($request->string('email')->toString(), $request->string('password')->toString()),
        ));
    }

    public function verify(Request $request, ChallengeCompletion $challenge): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);

        return response()->json($challenge->verify(
            AuthenticatedPlatformUser::from($request),
            $request->string('code')->toString(),
        ));
    }

    public function logout(Request $request, PlatformAudit $audit): Response
    {
        $user = AuthenticatedPlatformUser::from($request);
        $user->currentAccessToken()->delete();
        $audit->record('logout', $user);

        return response()->noContent();
    }
}
