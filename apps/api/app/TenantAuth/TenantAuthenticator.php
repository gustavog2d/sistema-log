<?php

namespace App\TenantAuth;

use App\Notifications\Notifier;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use stdClass;

/**
 * Autenticação por e-mail e senha no negócio da requisição. Não revela quais e-mails existem nem quem
 * tem vínculo: todas as falhas respondem igual e gastam o mesmo tempo; o bloqueio vale também para e-mail inexistente.
 */
class TenantAuthenticator
{
    public function __construct(private readonly AccessTokens $tokens, private readonly TenantTwoFactor $twoFactor, private readonly Notifier $notifier) {}

    /**
     * @return array<string, mixed> sessão, desafio de 2FA ou desafio de configuração
     *
     * @throws LoginFailed
     * @throws AccountIsLocked
     */
    public function login(string $email, #[SensitiveParameter] string $password): array
    {
        $email = mb_strtolower($email);
        $db = DB::connection('pgsql_auth');
        $tenantId = $this->tokens->tenantId();

        $user = $db->table('users')->where('email', $email)->first();
        $passwordOk = Hash::check($password, $user instanceof stdClass && is_string($user->password) ? $user->password : $this->dummyHash());

        $role = $user instanceof stdClass ? $this->roleOf($db, $tenantId, $user) : null;

        // Quem não tem vínculo com este negócio conta como inexistente aqui: o contador é por negócio e e-mail,
        // então ninguém trava a conta de outra pessoa errando a senha em outro negócio.
        if (! $user instanceof stdClass || $role === null) {
            $this->failUnknown($tenantId, $email);
        }

        if ($this->isLocked($user)) {
            throw new AccountIsLocked;
        }

        if (! $passwordOk) {
            $this->registerFailure($db, $user);

            throw new LoginFailed;
        }

        return $this->session($db, $tenantId, $user, $role);
    }

    /**
     * Segunda etapa: troca o desafio por um token de acesso depois de um código válido (TOTP ou de recuperação).
     *
     * @return array<string, mixed>
     *
     * @throws LoginFailed
     * @throws AccountIsLocked
     */
    public function completeChallenge(AuthenticatedPerson $person, string $code): array
    {
        $db = DB::connection('pgsql_auth');
        $user = $db->table('users')->where('id', $person->id)->first();

        if (! $user instanceof stdClass) {
            throw new LoginFailed;
        }

        if ($this->isLocked($user)) {
            throw new AccountIsLocked;
        }

        if (! $this->twoFactor->verify($db, $person->id, $code)) {
            $this->registerFailure($db, $user);

            throw new LoginFailed;
        }

        $this->tokens->revoke($person->tokenId);

        return $this->access($db, $this->tokens->tenantId(), $user, $person->role);
    }

    /**
     * Confere a senha atual da pessoa, contando a falha para o bloqueio.
     *
     * @throws AccountIsLocked
     * @throws ValidationException
     */
    public function confirmPassword(Connection $db, AuthenticatedPerson $person, #[SensitiveParameter] string $password): stdClass
    {
        $user = $db->table('users')->where('id', $person->id)->first();

        if (! $user instanceof stdClass) {
            throw ValidationException::withMessages(['current_password' => 'A senha atual está incorreta.']);
        }

        if ($this->isLocked($user)) {
            throw new AccountIsLocked;
        }

        if (! is_string($user->password) || ! Hash::check($password, $user->password)) {
            $this->registerFailure($db, $user);

            throw ValidationException::withMessages(['current_password' => 'A senha atual está incorreta.']);
        }

        return $user;
    }

    /**
     * Liga o 2FA com o primeiro código. Código errado conta para o bloqueio; quem entrou só para configurar precisa entrar de novo.
     *
     * @return list<string> códigos de recuperação
     *
     * @throws AccountIsLocked
     * @throws ValidationException
     */
    public function confirmTwoFactor(AuthenticatedPerson $person, string $code): array
    {
        $db = DB::connection('pgsql_auth');
        $user = $db->table('users')->where('id', $person->id)->first();

        if ($user instanceof stdClass && $this->isLocked($user)) {
            throw new AccountIsLocked;
        }

        $codes = $this->twoFactor->confirm($db, $person->id, $code);

        if ($codes === null) {
            if ($user instanceof stdClass) {
                $this->registerFailure($db, $user);
            }

            throw ValidationException::withMessages(['code' => 'O código não confere.']);
        }

        if ($person->tokenKind === AccessTokens::SETUP) {
            $this->tokens->revoke($person->tokenId);
        }

        return $codes;
    }

    /** Desliga o 2FA depois de conferir a senha atual. */
    public function disableTwoFactor(AuthenticatedPerson $person, #[SensitiveParameter] string $password): void
    {
        $db = DB::connection('pgsql_auth');
        $user = $this->confirmPassword($db, $person, $password);
        $this->twoFactor->disable($db, $person->id);

        // Só avisa quando havia 2FA ligado: desligar de novo não é um acontecimento.
        if ($user->totp_confirmed_at !== null) {
            $this->notifier->toPerson($this->tokens->tenantId(), $person->email, $person->name, 'two_factor_disabled', 'two_factor_disabled:'.$person->id.':'.intdiv(time(), 300));
        }
    }

    /** @return array{secret: string, otpauth_uri: string} */
    public function startTwoFactor(AuthenticatedPerson $person): array
    {
        return $this->twoFactor->setup(DB::connection('pgsql_auth'), $person->id, $person->email);
    }

    /**
     * Sessão completa, ou o desafio que falta (código do 2FA, ou configuração obrigatória para donas).
     *
     * @return array<string, mixed>
     */
    public function session(Connection $db, string $tenantId, stdClass $user, string $role): array
    {
        $userId = is_string($user->id) ? $user->id : '';

        if ($user->totp_confirmed_at !== null) {
            $challenge = $this->tokens->issue($db, $tenantId, $userId, AccessTokens::CHALLENGE);

            return ['challenge_token' => $challenge['token'], 'next' => 'verify', 'expires_at' => $challenge['expires_at']->toIso8601String()];
        }

        if ($role === 'owner' && Config::boolean('auth_tenant.owner_two_factor_required')) {
            $setup = $this->tokens->issue($db, $tenantId, $userId, AccessTokens::SETUP);

            return ['challenge_token' => $setup['token'], 'next' => 'setup', 'expires_at' => $setup['expires_at']->toIso8601String()];
        }

        return $this->access($db, $tenantId, $user, $role);
    }

    /** @return array<string, mixed> */
    public function access(Connection $db, string $tenantId, stdClass $user, string $role): array
    {
        // Só quem recebe o acesso de fato zera as falhas: a etapa da senha, sozinha, não desfaz as tentativas de código.
        $this->registerSuccess($db, $user);

        $issued = $this->tokens->issue($db, $tenantId, is_string($user->id) ? $user->id : '', AccessTokens::ACCESS);

        return [
            'access_token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_at' => $issued['expires_at']->toIso8601String(),
            'user' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $role,
            ],
        ];
    }

    public function roleOf(Connection $db, string $tenantId, stdClass $user): ?string
    {
        $role = $db->transaction(function (Connection $tx) use ($tenantId, $user) {
            // Vínculos têm RLS: o negócio é informado para a consulta enxergar só os dele.
            $tx->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);

            return $tx->table('memberships')->where('user_id', $user->id)->where('status', 'active')->value('role');
        });

        return is_string($role) ? $role : null;
    }

    public function isLocked(stdClass $user): bool
    {
        return is_string($user->locked_until) && now()->lessThan($user->locked_until);
    }

    /** Conta uma falha (senha ou código) e bloqueia ao atingir o limite, com a linha travada. */
    public function registerFailure(Connection $db, stdClass $user): void
    {
        $db->transaction(function (Connection $tx) use ($user): void {
            $fresh = $tx->table('users')->where('id', $user->id)->lockForUpdate()->first();

            if (! $fresh instanceof stdClass) {
                return;
            }

            $attempts = (is_numeric($fresh->failed_attempts) ? (int) $fresh->failed_attempts : 0) + 1;

            if ($attempts >= Config::integer('auth_tenant.max_failed_attempts')) {
                $tx->table('users')->where('id', $user->id)->update([
                    'failed_attempts' => 0, 'locked_until' => now()->addMinutes(Config::integer('auth_tenant.lock_minutes')),
                ]);

                return;
            }

            $tx->table('users')->where('id', $user->id)->update(['failed_attempts' => $attempts]);
        });
    }

    public function registerSuccess(Connection $db, stdClass $user): void
    {
        $db->table('users')->where('id', $user->id)->update(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()]);
    }

    /** E-mail inexistente também acumula falhas e bloqueia igual a uma conta real. */
    private function failUnknown(string $tenantId, string $email): never
    {
        $key = 'tenantauth:unknown:'.hash('sha256', $tenantId.'|'.$email);

        if (Cache::has($key.':locked')) {
            throw new AccountIsLocked;
        }

        $ttl = now()->addMinutes(Config::integer('auth_tenant.lock_minutes'));
        Cache::add($key, 0, $ttl);

        if (Cache::increment($key) >= Config::integer('auth_tenant.max_failed_attempts')) {
            Cache::put($key.':locked', true, $ttl);
            Cache::forget($key);
        }

        throw new LoginFailed;
    }

    /** Hash com o custo configurado: a senha é sempre conferida e o tempo não revela se a conta existe. */
    private function dummyHash(): string
    {
        return Cache::rememberForever('tenantauth:dummy-password-hash', fn () => Hash::make(bin2hex(random_bytes(16))));
    }
}
