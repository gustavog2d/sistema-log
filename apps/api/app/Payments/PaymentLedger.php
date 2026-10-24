<?php

namespace App\Payments;

use App\Appointments\Appointment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Regras dos pagamentos do atendimento (FIN-001). O controller só traduz o pedido. */
class PaymentLedger
{
    /** @return Collection<int, Payment> */
    public function list(Appointment $appointment): Collection
    {
        return Payment::query()->where('appointment_id', $appointment->id)->orderBy('paid_at')->orderBy('id')->get();
    }

    /** @return array{total_cents: int, received_cents: int, remaining_cents: int, payment_status: string, commission_percent: int, commission_cents: int} */
    public function summary(Appointment $appointment): array
    {
        $received = $this->received($appointment->id);
        $total = $appointment->value_total_cents;

        return [
            'total_cents' => $total ?? 0,
            'received_cents' => $received,
            'remaining_cents' => max(0, ($total ?? 0) - $received),
            'payment_status' => AppointmentFinance::status($total, $received),
            'commission_percent' => $appointment->commission_percent,
            'commission_cents' => AppointmentFinance::commissionCents($total, $appointment->commission_percent),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PaymentRuleViolation
     */
    public function record(Appointment $appointment, array $data): Payment
    {
        try {
            return DB::transaction(function () use ($appointment, $data): Payment {
                // Trava o agendamento: dois pagamentos ao mesmo tempo passam um de cada vez pela conferência do limite.
                $locked = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

                if (! in_array($locked->status, Appointment::ACTIVE, true)) {
                    throw new PaymentRuleViolation(409, 'appointment_closed', 'Agendamento cancelado ou com falta não recebe pagamento.');
                }

                $total = $locked->value_total_cents ?? 0;

                if ($total <= 0) {
                    throw new PaymentRuleViolation(422, 'appointment_without_value', 'Defina o valor do agendamento antes de registrar pagamentos.');
                }

                $amount = is_numeric($data['amount_cents'] ?? null) ? (int) $data['amount_cents'] : 0;
                $remaining = $total - $this->received($locked->id);

                if ($amount > $remaining) {
                    throw $this->exceeds($remaining);
                }

                return Payment::create([
                    'appointment_id' => $locked->id,
                    'type' => $data['type'], 'method' => $data['method'], 'amount_cents' => $amount,
                    'paid_at' => $data['paid_at'] ?? now()->utc()->format('Y-m-d H:i:s'),
                    'status' => 'received',
                ]);
            });
        } catch (QueryException $e) {
            // 23514 = o gatilho do banco (outra gravação confirmou antes e o total passaria do valor).
            if ($e->getCode() === '23514') {
                throw $this->exceeds(max(0, ($appointment->value_total_cents ?? 0) - $this->received($appointment->id)));
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data  tipo, forma e data (o valor nunca muda)
     *
     * @throws PaymentRuleViolation
     */
    public function update(Payment $payment, array $data): Payment
    {
        $this->assertReceived($payment);
        $payment->fill($data)->save();

        return $payment;
    }

    /** @throws PaymentRuleViolation */
    public function void(Payment $payment): Payment
    {
        $this->assertReceived($payment);
        $payment->fill(['status' => 'voided', 'voided_at' => Carbon::now()->utc()->format('Y-m-d H:i:s')])->save();

        return $payment;
    }

    public function received(string $appointmentId): int
    {
        return (int) Payment::query()->where('appointment_id', $appointmentId)->where('status', 'received')->sum('amount_cents');
    }

    private function assertReceived(Payment $payment): void
    {
        if ($payment->status !== 'received') {
            throw new PaymentRuleViolation(409, 'payment_voided', 'Este pagamento já foi estornado.');
        }
    }

    private function exceeds(int $remaining): PaymentRuleViolation
    {
        return new PaymentRuleViolation(422, 'payment_exceeds_total', 'O total recebido não pode passar do valor combinado do agendamento.', ['remaining_cents' => $remaining], 'amount_cents');
    }
}
