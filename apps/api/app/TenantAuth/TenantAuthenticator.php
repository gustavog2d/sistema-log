<?php

namespace App\TenantAuth;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;
use stdClass;

/**
 * Autenticação por e-mail e senha no negócio da requisição. Não revela quais e-mails existem nem quem
 * tem vínculo: todas as falhas respondem igual e gastam o mesmo tempo; o bloqueio vale também para e-mail inexistente.
 */
class TenantAuthenticator
{
    public function __construct(private readonly AccessTokens $tokens) {}

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

        if (! $user instanceof stdClass) {
            $this->failUnknown($tenantId, $email);
        }

        if ($this->isLocked($user)) {
            throw new AccountIsLocked;
        }

        $role = $this->roleOf($db, $tenantId, $user);

        if (! $passwordOk || $role === null) {
            $this->registerFailure($db, $user);

            throw new LoginFailed;
        }

        return $this->session($db, $tenantId, $user, $role);
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

            return $tx->table('memberships')->where('user_id', $user->id)->value('role');
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
