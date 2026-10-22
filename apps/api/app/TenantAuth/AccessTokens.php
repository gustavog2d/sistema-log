<?php

namespace App\TenantAuth;

use App\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tokens opacos do assinante (`{id}|{segredo}`). Só o hash do segredo é guardado, a linha pertence a um negócio
 * (RLS) e a emissão é feita pelo papel de autenticação, informando o negócio.
 */
class AccessTokens
{
    public const ACCESS = 'access';

    public const CHALLENGE = 'challenge';

    public const SETUP = 'setup';

    public function __construct(private readonly TenantContext $context) {}

    /**
     * Emite um token para a pessoa neste negócio.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function issue(Connection $db, string $tenantId, string $userId, string $kind): array
    {
        $ttl = $kind === self::ACCESS
            ? now()->addHours(Config::integer('auth_tenant.access_ttl_hours'))
            : now()->addMinutes(Config::integer('auth_tenant.challenge_ttl_minutes'));
        $id = (string) Str::uuid();
        $secret = Str::random(40);

        // O negócio é informado na transação: o RLS exige que o token seja deste negócio.
        $db->transaction(function (Connection $tx) use ($tenantId, $userId, $kind, $id, $secret, $ttl): void {
            $tx->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $tx->table('user_access_tokens')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'kind' => $kind,
                'token_hash' => hash('sha256', $secret), 'expires_at' => $ttl, 'created_at' => Instant::now(),
            ]);
        });

        return ['token' => $id.'|'.$secret, 'expires_at' => $ttl];
    }

    /** Apaga o token (a API só enxerga e apaga os do negócio em contexto). */
    public function revoke(string $tokenId): void
    {
        DB::table('user_access_tokens')->where('id', $tokenId)->delete();
    }

    public function tenantId(): string
    {
        return (string) $this->context->id();
    }
}
