<?php

namespace App\Admin;

use App\Models\Plan;
use App\Plans\EntitlementResolver;
use App\Plans\TenantEntitlementOverrides;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Leitura dos assinantes para a equipe da plataforma (PLT-009): lista e detalhe, sem segredos. */
class TenantDirectory
{
    public function __construct(
        private readonly EntitlementResolver $entitlements,
        private readonly TenantEntitlementOverrides $overrides,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function list(?string $search, ?string $status, int $limit): array
    {
        $query = $this->base()->orderByDesc('tenants.created_at')->orderByDesc('tenants.id');

        if ($search !== null && trim($search) !== '') {
            // Curingas do LIKE escritos pela pessoa valem como texto comum.
            $term = '%'.addcslashes(trim($search), '\\%_').'%';
            $query->where(fn (Builder $q) => $q->where('tenants.name', 'ilike', $term)->orWhere('tenants.slug', 'ilike', $term));
        }

        if ($status !== null) {
            $query->where('subscriptions.status', $status);
        }

        $page = $query->cursorPaginate($limit);

        return [
            'data' => array_values(collect($page->items())->map(fn (mixed $row): array => $this->item($row instanceof stdClass ? $row : new stdClass))->all()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ];
    }

    /** @return array<string, mixed>|null */
    public function find(string $tenantId): ?array
    {
        $row = $this->base()->where('tenants.id', $tenantId)->first();

        return $row instanceof stdClass ? $this->item($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function detail(string $tenantId): ?array
    {
        $item = $this->find($tenantId);

        if ($item === null) {
            return null;
        }

        $db = DB::connection('pgsql_platform');
        $subscription = $db->table('subscriptions')->where('tenant_id', $tenantId)->first();

        $extra = $db->transaction(function (Connection $tx) use ($tenantId): array {
            // Vínculos e faturas têm RLS: o negócio é informado na transação.
            $tx->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
            $owner = $tx->table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')
                ->where('memberships.role', 'owner')->where('memberships.status', 'active')->orderBy('memberships.created_at')->first(['users.name', 'users.email']);
            $invoices = $tx->table('invoices')->orderByDesc('period_start')->limit(5)
                ->get(['id', 'period_start', 'period_end', 'amount_cents', 'currency', 'status', 'paid_at']);

            return [
                'owner' => $owner instanceof stdClass ? ['name' => $owner->name, 'email' => $owner->email] : null,
                'people' => $tx->table('memberships')->where('status', 'active')->count(),
                'invoices' => $invoices->map(fn (stdClass $row): array => [
                    'id' => $row->id, 'period_start' => $this->iso($row->period_start), 'period_end' => $this->iso($row->period_end),
                    'amount_cents' => self::int($row->amount_cents), 'currency' => $row->currency, 'status' => $row->status, 'paid_at' => $this->iso($row->paid_at),
                ])->all(),
            ];
        });

        $method = $subscription instanceof stdClass && $subscription->payment_method_id !== null
            ? $db->table('payment_methods')->where('id', $subscription->payment_method_id)->first(['type', 'brand', 'last4']) : null;
        $coupon = $db->table('coupon_redemptions')->join('coupons', 'coupons.id', '=', 'coupon_redemptions.coupon_id')
            ->where('coupon_redemptions.tenant_id', $tenantId)->first(['coupons.code', 'coupon_redemptions.percent_off']);

        return $item + [
            'owner' => $extra['owner'],
            'active_people' => $extra['people'],
            'payment_method' => $method instanceof stdClass ? ['type' => $method->type, 'brand' => $method->brand, 'last4' => $method->last4] : null,
            'coupon' => $coupon instanceof stdClass ? ['code' => $coupon->code, 'percent_off' => self::int($coupon->percent_off)] : null,
            'invoices' => $extra['invoices'],
            'entitlements' => $this->effective($tenantId),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function effective(string $tenantId): array
    {
        $subscription = DB::connection('pgsql_platform')->table('subscriptions')->where('tenant_id', $tenantId)->first(['plan_id']);
        $plan = $subscription instanceof stdClass && is_string($subscription->plan_id) ? Plan::query()->find($subscription->plan_id) : null;

        if (! $plan instanceof Plan) {
            return [];
        }

        return array_values($this->entitlements->forPlan($plan, $this->overrides->for($tenantId, 'pgsql_platform'))->map->toArray()->all());
    }

    private function base(): Builder
    {
        return DB::connection('pgsql_platform')->table('tenants')
            ->leftJoin('subscriptions', 'subscriptions.tenant_id', '=', 'tenants.id')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->select([
                'tenants.id', 'tenants.name', 'tenants.slug', 'tenants.niche', 'tenants.created_at',
                'plans.key as plan', 'subscriptions.cycle', 'subscriptions.status', 'subscriptions.price_cents', 'subscriptions.currency',
                'subscriptions.trial_ends_at', 'subscriptions.current_period_end', 'subscriptions.canceled_at', 'subscriptions.suspended_reason',
            ]);
    }

    /** @return array<string, mixed> */
    private function item(stdClass $row): array
    {
        $pending = in_array($row->status, ['trialing', 'active'], true) && $row->canceled_at !== null;

        return [
            'id' => $row->id, 'name' => $row->name, 'slug' => $row->slug, 'niche' => $row->niche, 'created_at' => $this->iso($row->created_at),
            'plan' => $row->plan, 'cycle' => $row->cycle, 'status' => $row->status,
            'price_cents' => $row->price_cents === null ? null : self::int($row->price_cents), 'currency' => $row->currency,
            'trial_ends_at' => $this->iso($row->trial_ends_at), 'current_period_end' => $this->iso($row->current_period_end),
            'cancel_at_period_end' => $pending, 'suspended_reason' => $row->suspended_reason,
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function iso(mixed $value): ?string
    {
        return is_string($value) ? Carbon::parse($value, 'UTC')->toIso8601String() : null;
    }
}
