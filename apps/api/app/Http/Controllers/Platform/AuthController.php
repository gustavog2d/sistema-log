<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Platform\Auth\AccessTokens;
use App\Platform\Auth\AccountLocked;
use App\Platform\Auth\InvalidCredentials;
use App\Platform\Auth\LoginGuard;
use App\Platform\Auth\PlatformAudit;
use App\Platform\Auth\TwoFactor;
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

    public function verify(Request $request, TwoFactor $twoFactor, LoginGuard $guard, AccessTokens $tokens): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = $this->user($request);

        if ($user->isLocked()) {
            throw new AccountLocked;
        }

        if (! $twoFactor->verify($user, $request->string('code')->toString())) {
            $guard->registerFailure($user, 'wrong_code');

            throw new InvalidCredentials;
        }

        $request->user()?->currentAccessToken()->delete();
        $guard->registerSuccess($user);

        return response()->json($tokens->access($user));
    }

    public function logout(Request $request, PlatformAudit $audit): Response
    {
        $user = $this->user($request);
        $user->currentAccessToken()->delete();
        $audit->record('logout', $user);

        return response()->noContent();
    }

    private function user(Request $request): PlatformUser
    {
        $user = $request->user();
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
