<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Platform\Auth\AuthenticatedPlatformUser;
use App\Platform\Auth\ChallengeCompletion;
use App\Platform\Auth\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactor $twoFactor): JsonResponse
    {
        $user = AuthenticatedPlatformUser::from($request);
        abort_if($user->hasTwoFactor(), 403, 'O 2FA já está ativo.');

        return response()->json($twoFactor->setup($user));
    }

    public function confirm(Request $request, ChallengeCompletion $challenge): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:64']]);
        $user = AuthenticatedPlatformUser::from($request);

        abort_if($user->hasTwoFactor(), 403, 'O 2FA já está ativo.');

        return response()->json($challenge->confirmSetup($user, $request->string('code')->toString()));
    }
}
