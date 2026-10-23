<?php

namespace App\Team;

use App\Mail\InvitationMail;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Plans\EntitlementResolver;
use App\Tenancy\TenantContext;
use App\TenantAuth\AccountIsLocked;
use App\TenantAuth\AuthenticatedPerson;
use App\TenantAuth\TenantAuthenticator;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Equipe do negócio: convites, papéis e desativação. A dona gerencia todos; a gerente, todos menos a dona.
 * Escreve com o papel de autenticação, sempre informando o negócio na transação (RLS).
 */
class TeamService
{
    public const INVITE_DAYS = 7;

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantAuthenticator $authenticator,
        private readonly EntitlementResolver $entitlements,
    ) {}

    /** @return array{members: list<array<string, mixed>>, invitations: list<array<string, mixed>>} */
    public function team(): array
    {
        $members = DB::table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->orderBy('users.name')->select('users.id', 'users.name', 'users.email', 'memberships.role', 'memberships.status')->get();
        $invitations = DB::table('invitations')->where('expires_at', '>', now())->orderBy('created_at')
            ->select('id', 'name', 'email', 'role', 'expires_at')->get();

        return [
            'members' => array_values($members->map(fn (stdClass $row): array => $this->member($row))->all()),
            'invitations' => array_values($invitations->map(fn (stdClass $row): array => $this->invitation($row))->all()),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws TeamConflict
     */
    public function invite(AuthenticatedPerson $actor, string $name, string $email, string $role): array
    {
        $email = mb_strtolower($email);
        $tenant = $this->tenant();
        $token = Str::random(48);
        $id = (string) Str::uuid();
        $expires = now()->addDays(self::INVITE_DAYS);

        DB::connection('pgsql_auth')->transaction(function (Connection $db) use ($actor, $name, $email, $role, $token, $id, $expires, $tenant): void {
            $this->scope($db, $tenant->id);

            $user = $db->table('users')->where('email', $email)->first();

            if ($user instanceof stdClass && $db->table('memberships')->where('user_id', $user->id)->exists()) {
                throw new TeamConflict('Esta pessoa já faz parte da equipe.', 'already_member');
            }

            $db->table('invitations')->where('email', $email)->delete();
            $this->assertRoom($db, $tenant->id, 1);

            $db->table('invitations')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'email' => $email, 'name' => $name, 'role' => $role,
                'token_hash' => hash('sha256', $token), 'invited_by' => $actor->id, 'expires_at' => $expires,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $link = sprintf('%s://%s.%s/aceitar-convite?token=%s&email=%s', request()->getScheme(), $tenant->slug, Config::string('tenancy.base_domain'), $token, urlencode($email));
        $mail = new InvitationMail($tenant->name, $name, $link, $token);

        // Depois da resposta: o envio não atrasa o pedido nem revela nada pelo tempo.
        defer(function () use ($email, $mail): void {
            try {
                Mail::to($email)->send($mail);
            } catch (Throwable $e) {
                report(new \RuntimeException('Falha ao enviar o convite ('.$e::class.').'));
            }
        });

        return $this->invitation((object) ['id' => $id, 'name' => $name, 'email' => $email, 'role' => $role, 'expires_at' => $expires->toDateTimeString()]);
    }

    public function cancelInvitation(string $id): void
    {
        $deleted = DB::connection('pgsql_auth')->transaction(function (Connection $db) use ($id): int {
            $this->scope($db, $this->tenant()->id);

            return $db->table('invitations')->where('id', $id)->delete();
        });

        abort_if($deleted === 0, 404);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function changeRole(AuthenticatedPerson $actor, string $userId, string $role): array
    {
        return DB::connection('pgsql_auth')->transaction(function (Connection $db) use ($actor, $userId, $role): array {
            $this->scope($db, $this->tenant()->id);
            $target = $this->target($db, $actor, $userId);

            $db->table('memberships')->where('user_id', $userId)->update(['role' => $role, 'updated_at' => now()]);

            return $this->member((object) ['id' => $target->id, 'name' => $target->name, 'email' => $target->email, 'role' => $role, 'status' => $target->status]);
        });
    }

    /**
     * Desativar derruba, na hora, os tokens da pessoa neste negócio. Reativar respeita o limite do plano.
     *
     * @return array<string, mixed>
     *
     * @throws TeamConflict
     * @throws ValidationException
     */
    public function setActive(AuthenticatedPerson $actor, string $userId, bool $active): array
    {
        return DB::connection('pgsql_auth')->transaction(function (Connection $db) use ($actor, $userId, $active): array {
            $tenantId = $this->tenant()->id;
            $this->scope($db, $tenantId);
            $target = $this->target($db, $actor, $userId);

            if ($active && $target->status !== 'active') {
                $this->assertRoom($db, $tenantId, 1);
            }

            $db->table('memberships')->where('user_id', $userId)->update(['status' => $active ? 'active' : 'inactive', 'updated_at' => now()]);

            if (! $active) {
                $db->table('user_access_tokens')->where('user_id', $userId)->delete();
            }

            return $this->member((object) ['id' => $target->id, 'name' => $target->name, 'email' => $target->email, 'role' => $target->role, 'status' => $active ? 'active' : 'inactive']);
        });
    }

    /**
     * Aceita o convite: cria a conta (pessoa nova) ou confere a senha de quem já tem conta, vincula e abre a sessão.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws AccountIsLocked
     */
    public function accept(string $email, string $token, #[SensitiveParameter] string $password): array
    {
        $email = mb_strtolower($email);
        $tenantId = $this->tenant()->id;
        $invalid = ValidationException::withMessages(['token' => 'O convite é inválido ou expirou.']);

        $result = DB::connection('pgsql_auth')->transaction(function (Connection $db) use ($email, $token, $password, $tenantId, $invalid): array {
            $this->scope($db, $tenantId);
            $invitation = $db->table('invitations')->where('email', $email)->lockForUpdate()->first();

            if (
                ! $invitation instanceof stdClass || ! is_string($invitation->token_hash)
                || ! hash_equals($invitation->token_hash, hash('sha256', $token))
                || ! is_string($invitation->expires_at) || now()->greaterThanOrEqualTo(Carbon::parse($invitation->expires_at, 'UTC'))
            ) {
                throw $invalid;
            }

            $user = $db->table('users')->where('email', $email)->first();

            if ($user instanceof stdClass) {
                if ($db->table('memberships')->where('user_id', $user->id)->exists()) {
                    throw $invalid;
                }

                if ($this->authenticator->isLocked($user)) {
                    throw new AccountIsLocked;
                }

                if (! is_string($user->password) || ! Hash::check($password, $user->password)) {
                    // Fora da transação o contador não se perderia; aqui a exceção desfaria a falha, por isso é registrada depois.
                    return ['failure' => $user];
                }
            } else {
                $userId = (string) Str::uuid();
                $db->table('users')->insert([
                    'id' => $userId, 'name' => $invitation->name, 'email' => $email, 'password' => Hash::make($password),
                    'terms_accepted_at' => now(), 'terms_version' => Config::string('billing.terms_version'),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $user = $db->table('users')->where('id', $userId)->first();
            }

            if (! $user instanceof stdClass) {
                throw $invalid;
            }

            $db->table('memberships')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'user_id' => $user->id, 'role' => $invitation->role,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $db->table('invitations')->where('id', $invitation->id)->delete();

            return ['user' => $user, 'role' => is_string($invitation->role) ? $invitation->role : 'reception'];
        });

        $db = DB::connection('pgsql_auth');

        if (isset($result['failure'])) {
            $this->authenticator->registerFailure($db, $result['failure']);

            throw ValidationException::withMessages(['password' => 'A senha não confere.']);
        }

        return $this->authenticator->session($db, $tenantId, $result['user'], $result['role']);
    }

    /**
     * A pessoa alvo (deste negócio) e a regra de quem pode mexer nela.
     *
     * @throws ValidationException
     */
    private function target(Connection $db, AuthenticatedPerson $actor, string $userId): stdClass
    {
        $target = $db->table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
            ->where('memberships.user_id', $userId)->select('users.id', 'users.name', 'users.email', 'memberships.role', 'memberships.status')->first();

        abort_unless($target instanceof stdClass, 404);

        if ($target->id === $actor->id) {
            throw ValidationException::withMessages(['id' => 'Você não pode alterar a si mesmo.']);
        }

        // Só a dona mexe na dona, e isso nunca (ela é única); a gerente não pode.
        abort_if($target->role === 'owner' && $actor->role !== 'owner', 403, 'A dona do negócio não pode ser alterada.');

        if ($target->role === 'owner') {
            throw ValidationException::withMessages(['id' => 'A dona do negócio não pode ser alterada.']);
        }

        return $target;
    }

    /** Pessoas ativas mais convites pendentes, mais o que vai entrar, não podem passar do limite do plano. */
    private function assertRoom(Connection $db, string $tenantId, int $adding): void
    {
        $limit = $this->userLimit($tenantId);

        if ($limit === null) {
            return;
        }

        $used = $db->table('memberships')->where('status', 'active')->count()
            + $db->table('invitations')->where('expires_at', '>', now())->count();

        if ($used + $adding > $limit) {
            throw new TeamConflict('O plano atingiu o limite de usuários.', 'plan_limit_reached');
        }
    }

    private function userLimit(string $tenantId): ?int
    {
        $subscription = Subscription::query()->where('tenant_id', $tenantId)->first();
        $plan = $subscription === null ? null : Plan::query()->find($subscription->plan_id);

        if ($plan === null) {
            return null;
        }

        $entitlement = $this->entitlements->forPlan($plan)->firstWhere('feature', 'usuarios.max');

        return $entitlement !== null && $entitlement->enabled ? $entitlement->limit : null;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->context->current();
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function scope(Connection $db, string $tenantId): void
    {
        $db->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function member(stdClass $row): array
    {
        return ['id' => $row->id, 'name' => $row->name, 'email' => $row->email, 'role' => $row->role, 'status' => $row->status];
    }

    /**
     * @return array<string, mixed>
     */
    private function invitation(stdClass $row): array
    {
        return [
            'id' => $row->id, 'name' => $row->name, 'email' => $row->email, 'role' => $row->role, 'status' => 'invited',
            'expires_at' => Carbon::parse(is_string($row->expires_at) ? $row->expires_at : 'now', 'UTC')->toIso8601String(),
        ];
    }
}
