<?php

namespace App\Platform\Auth;

use App\Models\PlatformUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;

/** Regras de senha e bloqueio progressivo da conta. */
class LoginGuard
{
    /** Hash fixo para gastar o mesmo tempo quando o e-mail não existe. */
    private const DUMMY_HASH = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * @throws InvalidCredentials
     * @throws AccountLocked
     */
    public function attempt(string $email, string $password): PlatformUser
    {
        $user = PlatformUser::query()->where('email', mb_strtolower($email))->first();

        if ($user === null) {
            Hash::check($password, self::DUMMY_HASH);
            $this->audit->record('login.failed', null, ['reason' => 'unknown_user']);

            throw new InvalidCredentials;
        }

        if ($user->isLocked()) {
            $this->audit->record('login.locked', $user);

            throw new AccountLocked;
        }

        $passwordOk = Hash::check($password, $user->password);

        if (! $passwordOk || ! $user->active) {
            $this->registerFailure($user, $passwordOk ? 'inactive' : 'wrong_password');

            throw new InvalidCredentials;
        }

        $this->audit->record('login.password_ok', $user);

        return $user;
    }

    /** Conta uma falha (senha ou código) e bloqueia ao atingir o limite. */
    public function registerFailure(PlatformUser $user, string $reason): void
    {
        $user->failed_attempts++;

        if ($user->failed_attempts >= Config::integer('platform.max_failed_attempts')) {
            $user->locked_until = now()->addMinutes(Config::integer('platform.lock_minutes'));
            $user->failed_attempts = 0;
            $this->audit->record('login.locked', $user, ['reason' => $reason]);
        }

        $user->save();
        $this->audit->record('login.failed', $user, ['reason' => $reason]);
    }

    public function registerSuccess(PlatformUser $user): void
    {
        $user->forceFill(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()])->save();
        $this->audit->record('login.success', $user);
    }
}
