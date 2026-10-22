<?php

namespace App\Platform\Auth;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/** Regras de senha e bloqueio progressivo da conta. */
class LoginGuard
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * @throws InvalidCredentials
     * @throws AccountLocked
     */
    public function attempt(string $email, #[SensitiveParameter] string $password): PlatformUser
    {
        $email = mb_strtolower($email);
        $user = PlatformUser::query()->where('email', $email)->first();

        // A senha é sempre conferida (contra o hash real ou o fictício), para o tempo não revelar nada.
        $passwordOk = Hash::check($password, $user === null ? $this->dummyHash() : $user->password);

        if ($user === null) {
            return $this->failUnknown($email);
        }

        if ($user->isLocked()) {
            $this->audit->record('login.locked', $user);

            throw new AccountLocked;
        }

        if (! $passwordOk || ! $user->active) {
            $this->registerFailure($user, $passwordOk ? 'inactive' : 'wrong_password');

            throw new InvalidCredentials;
        }

        $this->audit->record('login.password_ok', $user);

        return $user;
    }

    /**
     * E-mail inexistente também acumula falhas (por hash do e-mail) e também é bloqueado, para que
     * o 423 não revele quais contas existem.
     */
    private function failUnknown(string $email): never
    {
        $key = 'platform:unknown:'.hash('sha256', $email);

        if (Cache::has($key.':locked')) {
            $this->audit->record('login.locked', null, ['reason' => 'unknown_user']);

            throw new AccountLocked;
        }

        $ttl = now()->addMinutes(Config::integer('platform.lock_minutes'));
        Cache::add($key, 0, $ttl);

        if (Cache::increment($key) >= Config::integer('platform.max_failed_attempts')) {
            Cache::put($key.':locked', true, $ttl);
            Cache::forget($key);
        }

        $this->audit->record('login.failed', null, ['reason' => 'unknown_user']);

        throw new InvalidCredentials;
    }

    /** Conta uma falha (senha ou código) e bloqueia ao atingir o limite. */
    public function registerFailure(PlatformUser $user, string $reason): void
    {
        // Linha travada: requisições paralelas não passam do limite de tentativas.
        $locked = DB::transaction(function () use ($user): bool {
            $fresh = PlatformUser::query()->lockForUpdate()->findOrFail($user->id);
            $fresh->failed_attempts++;

            $reachedLimit = $fresh->failed_attempts >= Config::integer('platform.max_failed_attempts');

            if ($reachedLimit) {
                $fresh->locked_until = now()->addMinutes(Config::integer('platform.lock_minutes'));
                $fresh->failed_attempts = 0;
            }

            $fresh->save();
            $user->setRawAttributes($fresh->getAttributes(), true);

            return $reachedLimit;
        });

        if ($locked) {
            $this->audit->record('login.locked', $user, ['reason' => $reason]);
        }

        $this->audit->record('login.failed', $user, ['reason' => $reason]);
    }

    public function registerSuccess(PlatformUser $user): void
    {
        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        $this->audit->record('login.success', $user);
    }

    /**
     * Hash com o custo configurado, para gastar o mesmo tempo quando não há senha a conferir.
     * Fica no cache: em PHP-FPM ou no servidor de desenvolvimento cada requisição é um processo novo,
     * e gerar o hash a cada pedido dobraria o tempo justamente do caminho que deveria igualar.
     */
    private function dummyHash(): string
    {
        return Cache::rememberForever('platform:dummy-password-hash', fn () => Hash::make(bin2hex(random_bytes(16))));
    }
}
