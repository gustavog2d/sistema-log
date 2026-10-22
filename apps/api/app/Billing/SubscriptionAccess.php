<?php

namespace App\Billing;

use App\Models\Subscription;
use Illuminate\Support\Carbon;

/**
 * Regra única de acesso por status da assinatura. Usada pela API (middleware) e pelo status exibido
 * ao assinante, para as duas respostas nunca divergirem.
 */
final class SubscriptionAccess
{
    /** Status com acesso completo. */
    public const WRITABLE = ['trialing', 'active'];

    public static function canWrite(?string $status): bool
    {
        return in_array($status, self::WRITABLE, true);
    }

    /** Código de erro de máquina quando a escrita é bloqueada; nulo quando permitida. */
    /** O negócio sem assinatura conta como sem acesso de escrita. */
    public static function canWriteFor(string $tenantId): bool
    {
        $status = Subscription::query()->where('tenant_id', $tenantId)->value('status');

        return self::canWrite(is_string($status) ? $status : null);
    }

    public static function blockedCode(?string $status): ?string
    {
        if (self::canWrite($status)) {
            return null;
        }

        return $status === 'canceled' ? 'subscription_canceled' : 'subscription_suspended';
    }

    /** Quando a próxima cobrança será tentada; nulo se suspensa ou cancelada. */
    public static function nextChargeAt(Subscription $subscription): ?Carbon
    {
        // Com cancelamento pedido, o vencimento encerra a assinatura em vez de cobrar.
        if ($subscription->canceled_at !== null) {
            return null;
        }

        return match ($subscription->status) {
            'trialing' => $subscription->trial_ends_at,
            'active' => $subscription->current_period_end,
            default => null,
        };
    }
}
