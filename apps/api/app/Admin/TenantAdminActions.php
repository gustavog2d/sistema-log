<?php

namespace App\Admin;

use App\Billing\SubscriptionConflict;
use App\Models\Feature;
use App\Models\PlatformUser;
use App\Notifications\Notifier;
use App\Platform\Auth\PlatformAudit;
use App\TenantAuth\AccessTokens;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Ações da equipe da plataforma sobre um assinante (PLT-009). Tudo vai para a auditoria, com quem fez. */
class TenantAdminActions
{
    public const IMPERSONATION_MINUTES = 30;

    public function __construct(
        private readonly PlatformAudit $audit,
        private readonly TenantDirectory $directory,
        private readonly AccessTokens $tokens,
        private readonly Notifier $notifier,
    ) {}

    /** @throws SubscriptionConflict */
    public function suspend(PlatformUser $actor, string $tenantId, string $reason): void
    {
        $this->changeSubscription($actor, $tenantId, 'tenant.suspended', function (Connection $db, stdClass $sub) use ($reason): array {
            if (! in_array($sub->status, ['trialing', 'active'], true)) {
                throw new SubscriptionConflict('Só uma assinatura em teste ou ativa pode ser suspensa.', 'not_suspendable');
            }

            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'suspended', 'suspended_at' => now(), 'suspended_reason' => $reason, 'updated_at' => now()]);

            return ['before' => ['status' => $sub->status], 'after' => ['status' => 'suspended'], 'reason' => $reason];
        });
    }

    /** @throws SubscriptionConflict */
    public function unsuspend(PlatformUser $actor, string $tenantId): void
    {
        $this->changeSubscription($actor, $tenantId, 'tenant.unsuspended', function (Connection $db, stdClass $sub): array {
            if ($sub->status !== 'suspended') {
                throw new SubscriptionConflict('A assinatura não está suspensa.', 'not_suspended');
            }

            // Quem nunca pagou volta para o teste (a cobrança acontece no vencimento); quem já pagou volta a ativa.
            $status = $sub->current_period_end === null ? 'trialing' : 'active';
            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => $status, 'suspended_at' => null, 'suspended_reason' => null, 'updated_at' => now()]);

            return ['before' => ['status' => 'suspended'], 'after' => ['status' => $status]];
        });
    }

    /** @throws SubscriptionConflict */
    public function extendTrial(PlatformUser $actor, string $tenantId, int $days): void
    {
        $this->changeSubscription($actor, $tenantId, 'tenant.trial_extended', function (Connection $db, stdClass $sub) use ($days): array {
            if ($sub->status !== 'trialing' || ! is_string($sub->trial_ends_at)) {
                throw new SubscriptionConflict('Só um teste em andamento pode ser estendido.', 'not_trialing');
            }

            $before = Carbon::parse($sub->trial_ends_at, 'UTC');
            $after = $before->copy()->addDays($days);
            $db->table('subscriptions')->where('id', $sub->id)->update(['trial_ends_at' => $after, 'updated_at' => now()]);

            return ['before' => ['trial_ends_at' => $before->toIso8601String()], 'after' => ['trial_ends_at' => $after->toIso8601String()], 'days' => $days];
        });
    }

    /**
     * @param  array{enabled: bool, limit?: int|null, visible?: bool}  $data
     * @return array<string, mixed> a funcionalidade efetiva
     *
     * @throws ValidationException
     */
    public function setEntitlement(PlatformUser $actor, string $tenantId, string $featureKey, array $data): array
    {
        $feature = $this->feature($featureKey);
        $limit = $data['limit'] ?? null;

        if ($feature->kind !== 'limit' && $limit !== null) {
            throw ValidationException::withMessages(['limit' => 'Esta funcionalidade não tem limite.']);
        }

        // Sem assinatura não há regra de plano para a exceção se sobrepor: confere antes de gravar.
        abort_unless($this->directory->effective($tenantId) !== [], 409, 'O negócio não tem assinatura.');

        $db = DB::connection('pgsql_platform');
        $where = ['tenant_id' => $tenantId, 'feature_id' => $feature->id];
        $before = $db->table('tenant_entitlement_overrides')->where($where)->first(['enabled', 'limit', 'visible']);
        $row = ['enabled' => $data['enabled'], 'limit' => $limit, 'visible' => $data['visible'] ?? true, 'created_by' => $actor->id, 'updated_at' => now()];

        // O id e a data de criação só entram na criação; trocar a exceção não muda a identidade da linha.
        if ($before instanceof stdClass) {
            $db->table('tenant_entitlement_overrides')->where($where)->update($row);
        } else {
            $db->table('tenant_entitlement_overrides')->insert($row + $where + ['id' => (string) Str::uuid(), 'created_at' => now()]);
        }

        $this->audit->record('tenant.entitlement_set', $actor, [
            'tenant' => $tenantId, 'feature' => $feature->key,
            'before' => $before instanceof stdClass ? (array) $before : null, 'after' => ['enabled' => $row['enabled'], 'limit' => $row['limit'], 'visible' => $row['visible']],
        ]);

        return $this->effectiveOf($tenantId, $feature->key);
    }

    /** @return array<string, mixed> */
    public function resetEntitlement(PlatformUser $actor, string $tenantId, string $featureKey): array
    {
        $feature = $this->feature($featureKey);
        $db = DB::connection('pgsql_platform');
        $before = $db->table('tenant_entitlement_overrides')->where('tenant_id', $tenantId)->where('feature_id', $feature->id)->first(['enabled', 'limit', 'visible']);
        $db->table('tenant_entitlement_overrides')->where('tenant_id', $tenantId)->where('feature_id', $feature->id)->delete();

        if ($before instanceof stdClass) {
            $this->audit->record('tenant.entitlement_reset', $actor, ['tenant' => $tenantId, 'feature' => $feature->key, 'before' => (array) $before]);
        }

        return $this->effectiveOf($tenantId, $feature->key);
    }

    /**
     * Acesso de suporte: token da dona, de curta duração e marcado, com motivo na auditoria e aviso à dona.
     *
     * @return array{access_token: string, token_type: string, expires_at: string, url: string}
     *
     * @throws SubscriptionConflict
     */
    public function impersonate(PlatformUser $actor, string $tenantId, string $reason): array
    {
        $tenant = DB::connection('pgsql_platform')->table('tenants')->where('id', $tenantId)->first(['slug']);
        abort_unless($tenant instanceof stdClass, 404);

        $db = DB::connection('pgsql_auth');
        $issued = $db->transaction(function (Connection $tx) use ($tenantId, $actor): ?array {
            $tx->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $owner = $tx->table('memberships')->where('role', 'owner')->where('status', 'active')->orderBy('created_at')->value('user_id');

            return is_string($owner) ? $this->tokens->issue($tx, $tenantId, $owner, AccessTokens::ACCESS, $actor->id, self::IMPERSONATION_MINUTES) : null;
        });

        if ($issued === null) {
            throw new SubscriptionConflict('O negócio não tem dona ativa.', 'no_active_owner');
        }

        $tokenId = explode('|', $issued['token'])[0];

        try {
            $this->audit->record('tenant.impersonated', $actor, ['tenant' => $tenantId, 'reason' => $reason, 'token' => $tokenId, 'expires_at' => $issued['expires_at']->toIso8601String()]);
        } catch (\Throwable $e) {
            // Sem rastro não há acesso: o token recém-emitido é apagado antes de falhar.
            DB::connection('pgsql_auth')->transaction(function (Connection $tx) use ($tenantId, $tokenId): void {
                $tx->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
                $tx->table('user_access_tokens')->where('id', $tokenId)->delete();
            });

            throw $e;
        }

        $this->notifier->toOwner($tenantId, 'support_access', 'support_access:'.$tenantId.':'.Str::uuid());

        return [
            'access_token' => $issued['token'], 'token_type' => 'Bearer', 'expires_at' => $issued['expires_at']->toIso8601String(),
            'url' => sprintf('%s://%s.%s', Config::string('tenancy.scheme'), is_string($tenant->slug) ? $tenant->slug : '', Config::string('tenancy.base_domain')),
        ];
    }

    /**
     * @param  callable(Connection, stdClass): array<string, mixed>  $change  aplica a mudança e devolve o que auditar
     */
    private function changeSubscription(PlatformUser $actor, string $tenantId, string $event, callable $change): void
    {
        $audited = DB::connection('pgsql_platform')->transaction(function (Connection $db) use ($tenantId, $change): array {
            $sub = $db->table('subscriptions')->where('tenant_id', $tenantId)->lockForUpdate()->first();
            abort_unless($sub instanceof stdClass, 404);

            if ($sub->status === 'canceled') {
                throw new SubscriptionConflict('A assinatura está cancelada.', 'subscription_canceled');
            }

            return $change($db, $sub);
        });

        $this->audit->record($event, $actor, ['tenant' => $tenantId] + $audited);
    }

    private function feature(string $key): Feature
    {
        return Feature::query()->where('key', $key)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function effectiveOf(string $tenantId, string $featureKey): array
    {
        $found = collect($this->directory->effective($tenantId))->firstWhere('feature', $featureKey);
        abort_unless(is_array($found), 409, 'O negócio não tem assinatura.');

        return $found;
    }
}
