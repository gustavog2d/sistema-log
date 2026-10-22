<?php

namespace Tests\Support;

use App\Models\PlatformUser;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;

trait PlatformTestHelpers
{
    public const PASSWORD = 'senha-segura-123';

    protected function platformUrl(string $path): string
    {
        return 'http://app.test/api/v1/platform/'.ltrim($path, '/');
    }

    protected function platformUser(bool $twoFactor = true, array $attributes = []): PlatformUser
    {
        $factory = PlatformUser::factory();

        if ($twoFactor) {
            $factory = $factory->withTwoFactor();
        }

        return $factory->create($attributes);
    }

    protected function otp(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    protected function login(string $email, string $password = self::PASSWORD): TestResponse
    {
        return $this->postJson($this->platformUrl('auth/login'), ['email' => $email, 'password' => $password]);
    }

    /** Chamada autenticada; esquece o usuário em cache para que cada chamada valide o token de novo. */
    protected function asToken(string $token, string $method, string $path, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->json($method, $this->platformUrl($path), $data);
    }

    /** Faz o fluxo completo (senha + código) e devolve o token de acesso. */
    protected function accessTokenFor(PlatformUser $user): string
    {
        $challenge = $this->login($user->email)->json('challenge_token');

        return $this->asToken($challenge, 'POST', 'auth/2fa/verify', ['code' => $this->otp((string) $user->totp_secret)])
            ->json('access_token');
    }
}
