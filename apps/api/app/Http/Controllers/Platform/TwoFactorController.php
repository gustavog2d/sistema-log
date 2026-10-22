<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\PlatformUser;
use App\Platform\Auth\AccessTokens;
use App\Platform\Auth\AccountLocked;
use App\Platform\Auth\InvalidCredentials;
use App\Platform\Auth\LoginGuard;
use App\Platform\Auth\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $user = $this->user($request);
        abort_if($user->hasTwoFactor(), 403, 'O 2FA já está ativo.');

        return response()->json($twoFactor->setup($user));
    }

    public function confirm(Request $request, TwoFactor $twoFactor, LoginGuard $guard, AccessTokens $tokens): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = $this->user($request);

        abort_if($user->hasTwoFactor(), 403, 'O 2FA já está ativo.');

        if ($user->isLocked()) {
            throw new AccountLocked;
        }

        $recoveryCodes = $twoFactor->confirm($user, $request->string('code')->toString());

        if ($recoveryCodes === null) {
            $guard->registerFailure($user, 'wrong_code');

            throw new InvalidCredentials;
        }

        $request->user()?->currentAccessToken()->delete();
        $guard->registerSuccess($user);

        return response()->json(['recovery_codes' => $recoveryCodes, ...$tokens->access($user)]);
    }

    private function user(Request $request): PlatformUser
    {
        $user = $request->user();
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
