<?php

namespace App\Billing\Fake;

use App\Billing\ChargeResult;
use App\Billing\PaymentGateway;
use App\Billing\PaymentMethodInfo;
use App\Billing\PaymentMethodRejected;
use Illuminate\Support\Str;

/**
 * Meio de pagamento de teste, simulado e sem estado externo.
 *
 * Tokens aceitos:
 *  - `tok_ok`: aprovado, cobra normalmente;
 *  - `tok_fail_on_charge`: aceito no cadastro, mas a cobrança falha (para testar a suspensão);
 *  - `tok_declined`: recusado já no cadastro.
 */
class FakePaymentGateway implements PaymentGateway
{
    public const TOKEN_OK = 'tok_ok';

    public const TOKEN_FAIL_ON_CHARGE = 'tok_fail_on_charge';

    public const TOKEN_DECLINED = 'tok_declined';

    /** @var list<array{customer: string, method: string, amount: int, currency: string, key: string, paid: bool}> */
    private array $charges = [];

    public function createCustomer(string $name, string $email): string
    {
        return 'cus_fake_'.Str::lower(Str::random(12));
    }

    public function attachPaymentMethod(string $customerRef, string $token): PaymentMethodInfo
    {
        return match ($token) {
            self::TOKEN_OK => new PaymentMethodInfo('pm_fake_ok_'.Str::lower(Str::random(8)), 'card', 'visa', '4242'),
            self::TOKEN_FAIL_ON_CHARGE => new PaymentMethodInfo('pm_fake_fail_'.Str::lower(Str::random(8)), 'card', 'visa', '0341'),
            default => throw new PaymentMethodRejected('Meio de pagamento recusado.'),
        };
    }

    public function charge(string $customerRef, string $paymentMethodRef, int $amountCents, string $currency, string $idempotencyKey): ChargeResult
    {
        foreach ($this->charges as $previous) {
            if ($previous['key'] === $idempotencyKey) {
                return new ChargeResult($previous['paid'], 'ch_fake_'.md5($idempotencyKey), $previous['paid'] ? null : 'card_declined');
            }
        }

        $paid = ! str_starts_with($paymentMethodRef, 'pm_fake_fail_');
        $this->charges[] = ['customer' => $customerRef, 'method' => $paymentMethodRef, 'amount' => $amountCents, 'currency' => $currency, 'key' => $idempotencyKey, 'paid' => $paid];

        return new ChargeResult($paid, 'ch_fake_'.md5($idempotencyKey), $paid ? null : 'card_declined');
    }

    /**
     * Cobranças feitas nesta instância (só para testes).
     *
     * @return list<array{customer: string, method: string, amount: int, currency: string, key: string, paid: bool}>
     */
    public function charges(): array
    {
        return $this->charges;
    }
}
