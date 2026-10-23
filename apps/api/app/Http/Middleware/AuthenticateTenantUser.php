<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Tenancy\TenantContext;
use App\TenantAuth\AuthenticatedPerson;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica a pessoa pelo token Bearer do negócio da requisição (`auth.tenant[:tipo,...]`, padrão `access`).
 * O RLS faz com que só os tokens do negócio sejam visíveis: o token de um negócio não vale em outro.
 */
class AuthenticateTenantUser
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string ...$kinds): Response
    {
        $kinds = $kinds === [] ? ['access'] : array_values($kinds);
        $person = null;
        $kindOk = true;

        if (preg_match('/^Bearer ([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\|([A-Za-z0-9]{40})$/', (string) $request->header('Authorization'), $match) === 1) {
            [$person, $kindOk] = $this->resolve($match[1], $match[2], $kinds);
        }

        if (! $person instanceof AuthenticatedPerson) {
            return $this->unauthorized();
        }

        if (! $kindOk) {
            return Problem::response(403, 'Forbidden', 'Este token não permite a operação.');
        }

        if ($person->impersonatedBy !== null) {
            // Acesso de suporte: só vale enquanto a pessoa da equipe continua ativa, e cada pedido deixa rastro (sem dado pessoal).
            if (! DB::table('platform_users')->where('id', $person->impersonatedBy)->where('active', true)->exists()) {
                return $this->unauthorized();
            }

            Log::info('support_access.request', ['impersonated_by' => $person->impersonatedBy, 'tenant' => $this->context->id(), 'method' => $request->method(), 'path' => $request->path()]);
        }

        $request->attributes->set('tenant_person', $person);

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    /**
     * @param  list<string>  $kinds
     * @return array{0: AuthenticatedPerson|null, 1: bool}
     */
    private function resolve(string $id, string $secret, array $kinds): array
    {
        if ($this->context->id() === null) {
            return [null, true];
        }

        $token = DB::table('user_access_tokens')->where('id', $id)->first();

        if (! $token instanceof stdClass || ! is_string($token->token_hash) || ! hash_equals($token->token_hash, hash('sha256', $secret))) {
            return [null, true];
        }

        if (! is_string($token->expires_at) || now()->greaterThanOrEqualTo($token->expires_at)) {
            return [null, true];
        }

        $user = DB::table('users')->select('id', 'name', 'email', 'tokens_valid_after', 'totp_confirmed_at')->where('id', $token->user_id)->first();
        $createdAt = is_string($token->created_at) ? $token->created_at : null;

        if (! $user instanceof stdClass || $createdAt === null) {
            return [null, true];
        }

        // Tokens emitidos antes de uma troca de senha ou de "sair de todos" não valem mais.
        if (is_string($user->tokens_valid_after) && $createdAt < $user->tokens_valid_after) {
            return [null, true];
        }

        $role = DB::table('memberships')->where('user_id', $user->id)->where('status', 'active')->value('role');

        if (! is_string($role)) {
            return [null, true];
        }

        $kind = is_string($token->kind) ? $token->kind : '';
        $this->touch($id, $token->last_used_at);

        return [
            new AuthenticatedPerson(
                id: is_string($user->id) ? $user->id : '',
                name: is_string($user->name) ? $user->name : '',
                email: is_string($user->email) ? $user->email : '',
                role: $role,
                tokenId: $id,
                tokenKind: $kind,
                twoFactorEnabled: $user->totp_confirmed_at !== null,
                impersonatedBy: is_string($token->impersonated_by) ? $token->impersonated_by : null,
            ),
            in_array($kind, $kinds, true),
        ];
    }

    /** Atualiza o último uso no máximo uma vez por minuto. */
    private function touch(string $id, mixed $lastUsed): void
    {
        if (is_string($lastUsed) && now()->diffInSeconds($lastUsed, true) < 60) {
            return;
        }

        DB::table('user_access_tokens')->where('id', $id)->update(['last_used_at' => now()]);
    }

    private function unauthorized(): Response
    {
        return Problem::response(401, 'Unauthorized', 'Autenticação necessária.', [], ['WWW-Authenticate' => 'Bearer']);
    }
}
