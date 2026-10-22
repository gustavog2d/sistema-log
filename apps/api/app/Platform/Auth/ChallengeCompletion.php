<?php

namespace App\Platform\Auth;

use App\Models\PlatformUser;
use SensitiveParameter;

/**
 * Segunda etapa do login: troca o token de desafio por um token de acesso depois de um código válido.
 * O token de desafio é sempre revogado ao fim (uso único).
 */
class ChallengeCompletion
{
    public function __construct(
        private readonly TwoFactor $twoFactor,
        private readonly LoginGuard $guard,
        private readonly AccessTokens $tokens,
    ) {}

    /**
     * @return array{access_token: string, token_type: string, expires_at: string}
     *
     * @throws AccountLocked
     * @throws InvalidCredentials
     */
    public function verify(PlatformUser $user, #[SensitiveParameter] string $code): array
    {
        $this->assertActiveAndUnlocked($user);

        if (! $this->twoFactor->verify($user, $code)) {
            $this->guard->registerFailure($user, 'wrong_code');

            throw new InvalidCredentials;
        }

        return $this->finish($user);
    }

    /**
     * @return array{recovery_codes: list<string>, access_token: string, token_type: string, expires_at: string}
     *
     * @throws AccountLocked
     * @throws InvalidCredentials
     */
    public function confirmSetup(PlatformUser $user, #[SensitiveParameter] string $code): array
    {
        $this->assertActiveAndUnlocked($user);

        $recoveryCodes = $this->twoFactor->confirm($user, $code);

        if ($recoveryCodes === null) {
            $this->guard->registerFailure($user, 'wrong_code');

            throw new InvalidCredentials;
        }

        return ['recovery_codes' => $recoveryCodes, ...$this->finish($user)];
    }

    /** @return array{access_token: string, token_type: string, expires_at: string} */
    private function finish(PlatformUser $user): array
    {
        $user->currentAccessToken()->delete();

        $this->guard->registerSuccess($user);

        return $this->tokens->access($user);
    }

    private function assertActiveAndUnlocked(PlatformUser $user): void
    {
        if (! $user->active) {
            throw new InvalidCredentials;
        }

        if ($user->isLocked()) {
            throw new AccountLocked;
        }
    }
}
