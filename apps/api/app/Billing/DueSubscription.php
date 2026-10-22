<?php

namespace App\Billing;

use Illuminate\Support\Carbon;
use stdClass;

/** Linha de assinatura lida do banco, com tipos garantidos. */
final readonly class DueSubscription
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $status,
        public string $cycle,
        public int $priceCents,
        public string $currency,
        public ?string $paymentMethodId,
        public string $customerRef,
        public ?Carbon $trialEndsAt,
        public ?Carbon $currentPeriodEnd,
    ) {}

    public static function fromRow(stdClass $row): self
    {
        $date = fn (mixed $value): ?Carbon => is_string($value) ? Carbon::parse($value) : null;

        return new self(
            id: self::text($row->id),
            tenantId: self::text($row->tenant_id),
            status: self::text($row->status),
            cycle: self::text($row->cycle),
            priceCents: is_numeric($row->price_cents) ? (int) $row->price_cents : 0,
            currency: self::text($row->currency),
            paymentMethodId: is_string($row->payment_method_id) ? $row->payment_method_id : null,
            customerRef: is_string($row->gateway_customer_ref) ? $row->gateway_customer_ref : '',
            trialEndsAt: $date($row->trial_ends_at),
            currentPeriodEnd: $date($row->current_period_end),
        );
    }

    /** Vencimento: fim do teste ou fim do período, conforme o status. */
    public function dueAt(): ?Carbon
    {
        return $this->status === 'trialing' ? $this->trialEndsAt : $this->currentPeriodEnd;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
