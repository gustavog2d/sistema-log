<?php

namespace App\Billing;

use App\Coupons\CouponService;
use App\Models\Plan;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Troca de plano pela dona (PLT-013). Em teste vale na hora; ativa: subir de preço vale na hora e cobra a diferença
 * proporcional ao que falta do período, descer agenda para o fim do período. Nunca passa do limite do plano novo.
 * Escreve com o papel de plataforma, na linha travada da assinatura.
 */
class PlanChanger
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PlatformAudit $audit,
        private readonly PlanLimits $limits,
    ) {}

    /**
     * Simulação, sem gravar nada.
     *
     * @return array<string, mixed>
     *
     * @throws SubscriptionConflict
     * @throws ValidationException
     */
    public function preview(string $tenantId, string $planKey): array
    {
        return DB::connection('pgsql_platform')->transaction(function (Connection $db) use ($tenantId, $planKey): array {
            $sub = $this->lock($db, $tenantId, lock: false);
            $target = $this->targetPlan($sub, $planKey);
            $plan = $this->evaluate($db, $sub, $target);

            return [
                'kind' => $plan['kind'], 'plan' => ['key' => $target->key, 'name' => $target->name],
                'price_cents' => $plan['price'], 'currency' => $this->text($sub->currency), 'charge_now_cents' => $plan['charge'],
                'effective_at' => $plan['effective_at']->toIso8601String(), 'violations' => $plan['violations'],
            ];
        });
    }

    /**
     * @return array{kind: string, plan: array{key: string, name: string}, price_cents: int, charged_cents: int, effective_at: string}
     *
     * @throws SubscriptionConflict
     * @throws PlanLimitExceeded
     * @throws PaymentFailed
     * @throws ValidationException
     */
    public function change(string $tenantId, string $planKey, string $actor): array
    {
        [$result, $event] = DB::connection('pgsql_platform')->transaction(function (Connection $db) use ($tenantId, $planKey, $actor): array {
            $sub = $this->lock($db, $tenantId);
            $target = $this->targetPlan($sub, $planKey);
            $plan = $this->evaluate($db, $sub, $target);

            if ($plan['violations'] !== []) {
                throw new PlanLimitExceeded($plan['violations']);
            }

            $from = $this->text($db->table('plans')->where('id', $sub->plan_id)->value('key'));
            $base = ['subscription' => $this->text($sub->id), 'tenant' => $tenantId, 'actor' => $actor, 'from' => $from, 'to' => $target->key];

            if ($plan['kind'] === 'next_cycle') {
                $db->table('subscriptions')->where('id', $sub->id)->update([
                    'pending_plan_id' => $target->id, 'pending_price_cents' => $plan['price'], 'updated_at' => now(),
                ]);
                $event = ['subscription.plan_change_scheduled', $base + ['price_cents' => $plan['price']]];
            } else {
                $this->applyNow($db, $sub, $target, $plan);
                $event = ['subscription.plan_changed', $base + ['price_cents' => $plan['price'], 'charged_cents' => $plan['charge']]];
            }

            return [[
                'kind' => $plan['kind'], 'plan' => ['key' => $target->key, 'name' => $target->name],
                'price_cents' => $plan['price'], 'charged_cents' => $plan['kind'] === 'now' ? $plan['charge'] : 0,
                'effective_at' => $plan['effective_at']->toIso8601String(),
            ], $event];
        });

        $this->audit([$event]);

        return $result;
    }

    /** @throws SubscriptionConflict */
    public function cancelPending(string $tenantId, string $actor): void
    {
        $event = DB::connection('pgsql_platform')->transaction(function (Connection $db) use ($tenantId, $actor): array {
            $sub = $this->lock($db, $tenantId, guard: false);

            if ($sub->pending_plan_id === null) {
                throw new SubscriptionConflict('Não há troca de plano agendada.', 'no_pending_plan_change');
            }

            $db->table('subscriptions')->where('id', $sub->id)->update(['pending_plan_id' => null, 'pending_price_cents' => null, 'updated_at' => now()]);

            return ['subscription.plan_change_canceled', ['subscription' => $this->text($sub->id), 'tenant' => $tenantId, 'actor' => $actor]];
        });

        $this->audit([$event]);
    }

    /**
     * Na renovação: aplica a troca agendada antes de cobrar. Se o uso passou do limite do plano novo, a troca é
     * descartada e a assinatura segue no plano atual. Devolve a linha atualizada e os eventos de auditoria.
     *
     * @return array{0: stdClass, 1: list<array{0: string, 1: array<string, scalar>}>}
     */
    public function applyPending(Connection $db, stdClass $row): array
    {
        if ($row->pending_plan_id === null) {
            return [$row, []];
        }

        $target = Plan::query()->whereKey($this->text($row->pending_plan_id))->first();
        $context = ['subscription' => $this->text($row->id), 'tenant' => $this->text($row->tenant_id)];
        $clear = ['pending_plan_id' => null, 'pending_price_cents' => null, 'updated_at' => now()];

        if ($target === null || $target->status !== 'active') {
            $db->table('subscriptions')->where('id', $row->id)->update($clear);

            return [$this->fresh($db, $row), [['subscription.plan_change_skipped', $context + ['reason' => 'plan_unavailable']]]];
        }

        if ($this->limits->violations($db, $this->text($row->tenant_id), $target) !== []) {
            $db->table('subscriptions')->where('id', $row->id)->update($clear);

            return [$this->fresh($db, $row), [['subscription.plan_change_skipped', $context + ['reason' => 'limit_exceeded', 'to' => $target->key]]]];
        }

        $price = is_numeric($row->pending_price_cents) ? (int) $row->pending_price_cents : 0;
        $from = $this->text($db->table('plans')->where('id', $row->plan_id)->value('key'));
        $db->table('subscriptions')->where('id', $row->id)->update($clear + ['plan_id' => $target->id, 'price_cents' => $price]);

        return [$this->fresh($db, $row), [['subscription.plan_change_applied', $context + ['from' => $from, 'to' => $target->key, 'price_cents' => $price]]]];
    }

    /**
     * @param  array{kind: string, price: int, charge: int, effective_at: Carbon, violations: list<array{feature: string, limit: int, usage: int}>}  $plan
     */
    private function applyNow(Connection $db, stdClass $sub, Plan $target, array $plan): void
    {
        $now = now()->utc();

        if ($plan['charge'] > 0) {
            $method = $sub->payment_method_id === null ? null : $db->table('payment_methods')->where('id', $sub->payment_method_id)->first();
            $ref = $method instanceof stdClass && is_string($method->gateway_ref) ? $method->gateway_ref : null;

            if ($ref === null || ! is_string($sub->gateway_customer_ref) || $sub->gateway_customer_ref === '') {
                throw new PaymentFailed;
            }

            $charge = $this->gateway->charge(
                $sub->gateway_customer_ref, $ref, $plan['charge'], $this->text($sub->currency),
                'plan:'.$this->text($sub->id).':'.$target->id.':'.$this->text($sub->current_period_end),
            );

            if (! $charge->paid) {
                throw new PaymentFailed;
            }

            $db->table('invoices')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $sub->tenant_id, 'subscription_id' => $sub->id,
                'period_start' => $now, 'period_end' => $plan['effective_at'], 'amount_cents' => $plan['charge'], 'currency' => $this->text($sub->currency),
                'status' => 'paid', 'gateway_charge_ref' => $charge->ref !== '' ? $charge->ref : null,
                'paid_at' => $now, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // O período não muda; só o plano e o preço (o fim do teste ou a próxima renovação cobram o preço novo).
        $db->table('subscriptions')->where('id', $sub->id)->update([
            'plan_id' => $target->id, 'price_cents' => $plan['price'], 'pending_plan_id' => null, 'pending_price_cents' => null, 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{kind: string, price: int, charge: int, effective_at: Carbon, violations: list<array{feature: string, limit: int, usage: int}>}
     */
    private function evaluate(Connection $db, stdClass $sub, Plan $target): array
    {
        $cycle = $this->text($sub->cycle);
        $list = $cycle === 'yearly' ? ($target->yearlyPriceCents() ?? 0) : ($target->monthly_price_cents ?? 0);
        $percent = $db->table('coupon_redemptions')->where('tenant_id', $sub->tenant_id)->value('percent_off');
        $price = is_numeric($percent) ? CouponService::priceAfter($list, (int) $percent) : $list;
        $current = is_numeric($sub->price_cents) ? (int) $sub->price_cents : 0;
        $violations = $this->limits->violations($db, $this->text($sub->tenant_id), $target);
        $now = now()->utc();

        if ($sub->status === 'trialing') {
            return ['kind' => 'now', 'price' => $price, 'charge' => 0, 'effective_at' => $now, 'violations' => $violations];
        }

        $start = Carbon::parse($this->text($sub->current_period_start), 'UTC');
        $end = Carbon::parse($this->text($sub->current_period_end), 'UTC');

        if ($price < $current) {
            return ['kind' => 'next_cycle', 'price' => $price, 'charge' => 0, 'effective_at' => $end, 'violations' => $violations];
        }

        $total = max(1, $start->diffInSeconds($end, true));
        $remaining = max(0, min($total, $now->diffInSeconds($end, false)));

        return [
            'kind' => 'now', 'price' => $price, 'charge' => (int) round(($price - $current) * $remaining / $total),
            'effective_at' => $end, 'violations' => $violations,
        ];
    }

    private function targetPlan(stdClass $sub, string $planKey): Plan
    {
        $target = Plan::query()->where('key', $planKey)->where('status', 'active')->first();

        if ($target === null || $target->id === $sub->plan_id) {
            throw ValidationException::withMessages(['plan' => 'Escolha outro plano ativo.']);
        }

        return $target;
    }

    private function lock(Connection $db, string $tenantId, bool $lock = true, bool $guard = true): stdClass
    {
        $db->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
        $query = $db->table('subscriptions')->where('tenant_id', $tenantId);
        $sub = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $sub instanceof stdClass) {
            throw new SubscriptionConflict('Este negócio não tem assinatura.', 'no_subscription');
        }

        if (! $guard) {
            return $sub;
        }

        if ($sub->status === 'canceled') {
            throw new SubscriptionConflict('A assinatura está cancelada.', 'subscription_canceled');
        }

        if ($sub->status === 'suspended') {
            throw new SubscriptionConflict('Regularize o pagamento antes de trocar de plano.', 'subscription_suspended');
        }

        if ($sub->canceled_at !== null) {
            throw new SubscriptionConflict('Desfaça o cancelamento antes de trocar de plano.', 'cancel_pending');
        }

        return $sub;
    }

    private function fresh(Connection $db, stdClass $row): stdClass
    {
        $fresh = $db->table('subscriptions')->where('id', $row->id)->first();

        return $fresh instanceof stdClass ? $fresh : $row;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** @param  list<array{0: string, 1: array<string, scalar>}>  $events */
    private function audit(array $events): void
    {
        foreach ($events as [$event, $metadata]) {
            try {
                $this->audit->record($event, null, $metadata);
            } catch (Throwable $e) {
                // A troca já foi gravada: falha de auditoria nunca a desfaz.
                report($e);
            }
        }
    }
}
