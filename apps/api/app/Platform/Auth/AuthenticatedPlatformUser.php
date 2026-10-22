<?php

namespace App\Platform\Auth;

use App\Models\PlatformUser;
use Illuminate\Http\Request;

final class AuthenticatedPlatformUser
{
    public static function from(Request $request): PlatformUser
    {
        $user = $request->user();
        abort_unless($user instanceof PlatformUser, 401);

        return $user;
    }
}
