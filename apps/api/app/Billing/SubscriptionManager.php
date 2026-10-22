<?php

namespace App\Billing;

use App\Models\Subscription;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * Gestão da assinatura pela dona: trocar o meio de pagamento (e regularizar uma assinatura suspensa), pedir e
 * desfazer o cancelamento. Escreve com o papel de plataforma, na linha travada da assinatura do negócio.
 */
class SubscriptionManager
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PlatformAudit $audit,
    ) {}

    /**
     * Em teste ou ativa só troca o meio. Suspensa: cobra o plano agora e, se aprovado, volta a ativa.
     *
     * @throws SubscriptionConflict
     * @throws PaymentFailed
     * @throws ValidationException
     */
    public function changePaymentMethod(string $tenantId, string $token, string $actor): void
    {
        $events = DB::connection('pgsql_platform')->transaction(function (Connection $db) use ($tenantId, $token, $actor): array {
            $sub = $this->lock($db, $tenantId);
            $this->refuseIfCanceled($sub);

            if (! is_string($sub->gateway_customer_ref) || $sub->gateway_customer_ref === '') {
                throw new SubscriptionConflict('A assinatura não tem cliente no gateway de pagamento.', 'no_gateway_customer');
            }

            try {
                $method = $this->gateway->attachPaymentMethod($sub->gateway_customer_ref, $token);
            } catch (PaymentMethodRejected) {
                throw ValidationException::withMessages(['payment_method_token' => 'O meio de pagamento foi recusado.']);
            }

            $suspended = $sub->status === 'suspended';
            $price = is_numeric($sub->price_cents) ? (int) $sub->price_cents : 0;
            $currency = is_string($sub->currency) ? $sub->currency : 'BRL';
            $now = now()->utc();
            $charge = null;

            if ($suspended && $price > 0) {
                $charge = $this->gateway->charge(
                    $sub->gateway_customer_ref, $method->ref, $price, $currency,
                    'reg:'.$this->text($sub->id).':'.$this->text($sub->suspended_at).':'.hash('sha256', $method->ref),
                );

                if (! $charge->paid) {
                    throw new PaymentFailed;
                }
            }

            $methodId = (string) Str::uuid();
            $db->table('payment_methods')->insert([
                'id' => $methodId, 'tenant_id' => $tenantId, 'gateway_ref' => $method->ref,
                'type' => $method->type, 'brand' => $method->brand, 'last4' => $method->last4,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            if (! $suspended) {
                $db->table('subscriptions')->where('id', $sub->id)->update(['payment_method_id' => $methodId, 'updated_at' => now()]);

                return [['subscription.payment_method_changed', ['subscription' => $this->text($sub->id), 'tenant' => $tenantId, 'actor' => $actor]]];
            }

            $end = $sub->cycle === 'yearly' ? $now->copy()->addYearNoOverflow() : $now->copy()->addMonthNoOverflow();
            $db->table('invoices')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'subscription_id' => $sub->id,
                'period_start' => $now, 'period_end' => $end, 'amount_cents' => $price, 'currency' => $currency,
                'status' => 'paid', 'gateway_charge_ref' => $charge !== null && $charge->ref !== '' ? $charge->ref : null,
                'paid_at' => $now, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $db->table('subscriptions')->where('id', $sub->id)->update([
                'status' => 'active', 'payment_method_id' => $methodId, 'suspended_at' => null,
                'current_period_start' => $now, 'current_period_end' => $end, 'updated_at' => now(),
            ]);

            return [['subscription.payment_method_changed', ['subscription' => $this->text($sub->id), 'tenant' => $tenantId, 'actor' => $actor]], ['subscription.regularized', ['subscription' => $this->text($sub->id), 'tenant' => $tenantId, 'actor' => $actor, 'amount_cents' => $price]]];
        });

        $this->record($events);
    }

    /**
     * Em teste ou ativa o acesso vai até o fim do teste ou do período pago e nada mais é cobrado; suspensa cancela na hora.
     *
     * @throws SubscriptionConflict
     */
    public function cancel(string $tenantId, string $actor): void
    {
        $events = DB::connection('pgsql_platform')->transaction(function (Connection $db) use ($tenantId, $actor): array {
            $sub = $this->lock($db, $tenantId);
            $this->refuseIfCanceled($sub);
            $context = ['subscription' => $this->text($sub->id), 'tenant' => $tenantId, 'actor' => $actor];

            if ($sub->status === 'suspended') {
                $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'canceled', 'canceled_at' => now(), 'updated_at' => now()]);

                return [['subscription.canceled', $context]];
            }

            if ($sub->canceled_at !== null) {
                return [];
            }

            $db->table('subscriptions')->where('id', $sub->id)->update(['canceled_at' => now(), 'updated_at' => now()]);

            return [['subscription.cancel_requested', $context]];
        });

        $this->record($events);
    }

    /** @throws SubscriptionConflict */
    public function reactivate(string $tenantId, string $actor): void
    {
        $events = DB::connection('pgsql_platform')->transaction(function (Connection $db) use ($tenantId, $actor): array {
            $sub = $this->lock($db, $tenantId);
            $this->refuseIfCanceled($sub);

            if (! in_array($sub->status, ['trialing', 'active'], true) || $sub->canceled_at === null) {
                throw new SubscriptionConflict('Não há cancelamento pendente para desfazer.', 'nothing_to_reactivate');
            }

            $db->table('subscriptions')->where('id', $sub->id)->update(['canceled_at' => null, 'updated_at' => now()]);

            return [['subscription.reactivated', ['subscription' => $this->text($sub->id), 'tenant' => $tenantId, 'actor' => $actor]]];
        });

        $this->record($events);
    }

    /** @return array<string, mixed> o estado atual, para a resposta das ações e para `GET /subscription` */
    public static function summary(Subscription $subscription): array
    {
        $method = $subscription->payment_method_id === null ? null : DB::table('payment_method_summaries')->where('id', $subscription->payment_method_id)->first();
        $pending = in_array($subscription->status, ['trialing', 'active'], true) && $subscription->canceled_at !== null;

        return [
            'status' => $subscription->status,
            'cancel_at_period_end' => $pending,
            'can_write' => SubscriptionAccess::canWrite($subscription->status),
            'current_period_end' => $subscription->current_period_end?->toIso8601String(),
            'payment_method' => $method instanceof stdClass ? ['type' => $method->type, 'brand' => $method->brand, 'last4' => $method->last4] : null,
        ];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function lock(Connection $db, string $tenantId): stdClass
    {
        $db->statement("select set_config('app.tenant_id', ?, true)", [$tenantId]);
        $sub = $db->table('subscriptions')->where('tenant_id', $tenantId)->lockForUpdate()->first();

        if (! $sub instanceof stdClass) {
            throw new SubscriptionConflict('Este negócio não tem assinatura.', 'no_subscription');
        }

        return $sub;
    }

    private function refuseIfCanceled(stdClass $sub): void
    {
        if ($sub->status === 'canceled') {
            throw new SubscriptionConflict('A assinatura está cancelada.', 'subscription_canceled');
        }
    }

    /** @param  list<array{0: string, 1: array<string, scalar>}>  $events */
    private function record(array $events): void
    {
        foreach ($events as [$event, $metadata]) {
            try {
                $this->audit->record($event, null, $metadata);
            } catch (Throwable $e) {
                // A ação já foi gravada: falha de auditoria nunca a desfaz.
                report($e);
            }
        }
    }
}
