<?php

namespace App\Platform\Auth;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Config;

/** Emissão dos tokens da equipe da plataforma: desafio (curto, uma única etapa) e acesso. */
class AccessTokens
{
    public const CHALLENGE_VERIFY = 'platform:challenge:verify';

    public const CHALLENGE_SETUP = 'platform:challenge:setup';

    public const ACCESS = 'platform:access';

    /** @return array{challenge_token: string, next: string, expires_at: string} */
    public function challenge(PlatformUser $user): array
    {
        $next = $user->hasTwoFactor() ? 'verify' : 'setup';
        $expiresAt = now()->addMinutes(Config::integer('platform.challenge_ttl_minutes'));

        $token = $user->createToken(
            'challenge',
            [$next === 'verify' ? self::CHALLENGE_VERIFY : self::CHALLENGE_SETUP],
            $expiresAt,
        );

        return [
            'challenge_token' => $token->plainTextToken,
            'next' => $next,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    /** @return array{access_token: string, token_type: string, expires_at: string} */
    public function access(PlatformUser $user): array
    {
        $expiresAt = now()->addHours(Config::integer('platform.access_ttl_hours'));
        $token = $user->createToken('access', [self::ACCESS], $expiresAt);

        return [
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
