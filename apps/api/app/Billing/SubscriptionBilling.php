<?php

namespace App\Billing;

use App\Platform\Auth\PlatformAudit;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

/**
 * Cobra as assinaturas vencidas: o fim do teste e as renovações. Cobrança recusada suspende na hora,
 * sem carência (regra de negócio). Falha técnica não muda nada: a assinatura é tentada de novo na próxima execução.
 */
class SubscriptionBilling
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PlatformAudit $audit,
    ) {}

    public function run(Carbon $asOf): BillingRunSummary
    {
        $summary = new BillingRunSummary;

        $ids = DB::connection('pgsql_platform')->table('subscriptions')
            ->where(function (Builder $query) use ($asOf): void {
                $query->where(fn (Builder $trial) => $trial->where('status', 'trialing')->where('trial_ends_at', '<=', $asOf))
                    ->orWhere(fn (Builder $active) => $active->where('status', 'active')->where('current_period_end', '<=', $asOf));
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): string => is_string($id) ? $id : '')
            ->filter();

        foreach ($ids as $id) {
            try {
                $events = DB::connection('pgsql_platform')->transaction(fn (Connection $db) => $this->process($db, $id, $asOf, $summary));
            } catch (Throwable $e) {
                $summary->errors++;
                report($e);

                continue;
            }

            $this->audit($events);
        }

        return $summary;
    }

    /**
     * @return list<array{0: string, 1: array<string, scalar>}> eventos de auditoria a gravar depois da transação
     */
    private function process(Connection $db, string $id, Carbon $asOf, BillingRunSummary $summary): array
    {
        // Linha travada: duas execuções simultâneas nunca processam a mesma assinatura.
        $row = $db->table('subscriptions')->where('id', $id)->lockForUpdate()->first();
        $sub = $row instanceof stdClass ? DueSubscription::fromRow($row) : null;
        $due = $sub?->dueAt();

        if ($sub === null || $due === null || ! $this->isDue($sub, $asOf)) {
            return [];
        }

        $db->statement("select set_config('app.tenant_id', ?, true)", [$sub->tenantId]);

        [$start, $end] = $this->period($due, $sub->cycle, $asOf);
        $result = $this->charge($db, $sub, $start);

        $db->table('invoices')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $sub->tenantId, 'subscription_id' => $sub->id,
            'period_start' => $start, 'period_end' => $end, 'amount_cents' => $sub->priceCents, 'currency' => $sub->currency,
            'status' => $result->paid ? 'paid' : 'failed', 'failure_reason' => $result->failureReason,
            'gateway_charge_ref' => $result->ref !== '' ? $result->ref : null, 'paid_at' => $result->paid ? $asOf : null,
            'created_at' => $asOf, 'updated_at' => $asOf,
        ]);

        $context = ['subscription' => $sub->id, 'tenant' => $sub->tenantId, 'amount_cents' => $sub->priceCents];

        if ($result->paid) {
            $db->table('subscriptions')->where('id', $sub->id)->update([
                'status' => 'active', 'current_period_start' => $start, 'current_period_end' => $end, 'updated_at' => $asOf,
            ]);
            $summary->charged++;

            return [['billing.charged', $context]];
        }

        // Pagamento não aprovado: suspensão imediata, sem carência e sem nova tentativa automática.
        $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'suspended', 'suspended_at' => $asOf, 'updated_at' => $asOf]);
        $summary->suspended++;

        return [['billing.failed', $context + ['reason' => (string) $result->failureReason]], ['subscription.suspended', $context]];
    }

    private function charge(Connection $db, DueSubscription $sub, Carbon $start): ChargeResult
    {
        if ($sub->priceCents === 0) {
            // Cortesia: nada a cobrar, o gateway não é chamado.
            return new ChargeResult(true, '');
        }

        $method = $sub->paymentMethodId === null ? null : $db->table('payment_methods')->where('id', $sub->paymentMethodId)->first();
        $methodRef = $method instanceof stdClass && is_string($method->gateway_ref) ? $method->gateway_ref : null;

        if ($methodRef === null) {
            return new ChargeResult(false, '', 'no_payment_method');
        }

        // Chave determinística (assinatura + início do período): a mesma cobrança nunca é feita duas vezes.
        return $this->gateway->charge($sub->customerRef, $methodRef, $sub->priceCents, $sub->currency, 'sub:'.$sub->id.':'.$start->format('YmdHis'));
    }

    private function isDue(DueSubscription $sub, Carbon $asOf): bool
    {
        return in_array($sub->status, ['trialing', 'active'], true) && $sub->dueAt() !== null && $sub->dueAt()->lessThanOrEqualTo($asOf);
    }

    /**
     * O período começa no vencimento. Se o atraso for de um ciclo inteiro ou mais, começa na execução
     * (cobra um período só, não os antigos em sequência).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Carbon $due, string $cycle, Carbon $asOf): array
    {
        $end = fn (Carbon $start): Carbon => $cycle === 'yearly' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();

        $start = $due->copy();

        if ($end($start)->lessThanOrEqualTo($asOf)) {
            $start = $asOf->copy();
        }

        return [$start, $end($start)];
    }

    /** @param  list<array{0: string, 1: array<string, scalar>}>  $events */
    private function audit(array $events): void
    {
        foreach ($events as [$event, $metadata]) {
            try {
                $this->audit->record($event, null, $metadata);
            } catch (Throwable $e) {
                // A cobrança já foi registrada: falha de auditoria nunca a desfaz.
                report($e);
            }
        }
    }
}
