<?php

namespace App\Billing;

use App\Notifications\Notifier;
use App\Platform\Auth\PlatformAudit;
use App\Support\Money;
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
        private readonly Notifier $notifier,
    ) {}

    public function run(Carbon $asOf): BillingRunSummary
    {
        // Sempre em UTC (o banco guarda UTC) e sem microssegundos: o período e a chave de idempotência usam o mesmo instante.
        $asOf = $asOf->copy()->utc()->startOfSecond();
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
                $events = DB::connection('pgsql_platform')->transaction(fn (Connection $db) => $this->process($db, $id, $asOf));
            } catch (Throwable $e) {
                $summary->errors++;
                report($e);
                $this->audit([['billing.error', ['subscription' => $id, 'error' => $e::class]]]);

                continue;
            }

            // Contadores só depois do commit: se a transação falhar, nada foi contado.
            foreach ($events as [$event]) {
                match ($event) {
                    'billing.charged' => $summary->charged++,
                    'subscription.suspended' => $summary->suspended++,
                    default => null,
                };
            }

            $this->audit($events);
        }

        return $summary;
    }

    /**
     * @return list<array{0: string, 1: array<string, scalar>}> eventos de auditoria a gravar depois da transação
     */
    private function process(Connection $db, string $id, Carbon $asOf): array
    {
        // Linha travada: duas execuções simultâneas nunca processam a mesma assinatura (a que perde pula a linha).
        $row = $db->table('subscriptions')->where('id', $id)->lock('for update skip locked')->first();
        $sub = $row instanceof stdClass ? DueSubscription::fromRow($row) : null;
        $due = $sub?->dueAt();

        if ($sub === null || $due === null || ! $this->isDue($sub, $asOf)) {
            return [];
        }

        $db->statement("select set_config('app.tenant_id', ?, true)", [$sub->tenantId]);

        // Cancelamento pedido pela dona: no vencimento a assinatura termina, sem cobrar e sem fatura.
        if ($row->canceled_at !== null) {
            $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'canceled', 'updated_at' => now()]);

            return [['subscription.canceled', ['subscription' => $sub->id, 'tenant' => $sub->tenantId, 'mark' => 'end']]];
        }

        [$start, $end] = $this->period($due, $sub->cycle, $asOf, $sub->billingDay());
        $result = $this->charge($db, $sub, $due);

        $db->table('invoices')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $sub->tenantId, 'subscription_id' => $sub->id,
            'period_start' => $start, 'period_end' => $end, 'amount_cents' => $sub->priceCents, 'currency' => $sub->currency,
            'status' => $result->paid ? 'paid' : 'failed', 'failure_reason' => $result->failureReason,
            'gateway_charge_ref' => $result->ref !== '' ? $result->ref : null, 'paid_at' => $result->paid ? $asOf : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $context = ['subscription' => $sub->id, 'tenant' => $sub->tenantId, 'amount_cents' => $sub->priceCents];

        if ($result->paid) {
            $db->table('subscriptions')->where('id', $sub->id)->update([
                'status' => 'active', 'current_period_start' => $start, 'current_period_end' => $end, 'updated_at' => now(),
            ]);

            return [['billing.charged', $context + ['mark' => $start->format('YmdHis'), 'amount' => Money::brl($sub->priceCents), 'period_end' => $end->copy()->setTimezone('America/Sao_Paulo')->format('d/m/Y')]]];
        }

        // Pagamento não aprovado: suspensão imediata, sem carência e sem nova tentativa automática.
        $db->table('subscriptions')->where('id', $sub->id)->update(['status' => 'suspended', 'suspended_at' => $asOf, 'updated_at' => now()]);

        $suspended = $context + ['mark' => $due->format('YmdHis'), 'reason' => self::reasonText((string) $result->failureReason)];

        return [['billing.failed', $context + ['reason' => (string) $result->failureReason]], ['subscription.suspended', $suspended]];
    }

    private function charge(Connection $db, DueSubscription $sub, Carbon $due): ChargeResult
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

        // Chave determinística (assinatura + vencimento original, que nunca muda mesmo com atraso longo):
        // uma nova tentativa depois de uma falha após a cobrança nunca cobra de novo.
        return $this->gateway->charge($sub->customerRef, $methodRef, $sub->priceCents, $sub->currency, 'sub:'.$sub->id.':due:'.$due->format('YmdHis'));
    }

    private function isDue(DueSubscription $sub, Carbon $asOf): bool
    {
        return in_array($sub->status, ['trialing', 'active'], true) && $sub->dueAt() !== null && $sub->dueAt()->lessThanOrEqualTo($asOf);
    }

    /**
     * O período começa no vencimento. Se o atraso for de um ciclo inteiro ou mais, começa na execução
     * (cobra um período só, não os antigos em sequência). O dia da cobrança é o do fim do teste e é
     * preservado: 31/01 → 28/02 → 31/03 (um mês curto não muda o dia para sempre).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Carbon $due, string $cycle, Carbon $asOf, int $billingDay): array
    {
        $end = function (Carbon $start) use ($cycle, $billingDay): Carbon {
            $next = $cycle === 'yearly' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();

            return $next->day(min($billingDay, $next->daysInMonth));
        };

        $start = $due->copy();

        if ($end($start)->lessThanOrEqualTo($asOf)) {
            $start = $asOf->copy();
        }

        return [$start, $end($start)];
    }

    /** Motivo da recusa em texto para a dona (nada técnico do gateway). */
    private static function reasonText(string $reason): string
    {
        return match ($reason) {
            'no_payment_method' => 'não há meio de pagamento cadastrado',
            default => 'o pagamento foi recusado',
        };
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

            // O aviso por e-mail nunca atrapalha a cobrança (o Notifier trata os próprios erros).
            $this->notifier->forSubscriptionEvent($event, $metadata);
        }
    }
}
