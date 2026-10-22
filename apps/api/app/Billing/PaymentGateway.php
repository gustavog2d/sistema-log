<?php

namespace App\Billing;

/**
 * Meio de pagamento por trás de uma interface própria: o domínio não conhece o fornecedor.
 * Hoje só existe o gateway de teste (`fake`); o gateway real entra por último.
 */
interface PaymentGateway
{
    /** Cria o cliente no gateway e devolve a referência dele. */
    public function createCustomer(string $name, string $email): string;

    /**
     * Valida e vincula o meio de pagamento ao cliente, sem cobrar.
     *
     * @throws PaymentMethodRejected
     */
    public function attachPaymentMethod(string $customerRef, string $token): PaymentMethodInfo;

    /** Cobra um valor. A mesma chave de idempotência nunca cobra duas vezes. */
    public function charge(string $customerRef, string $paymentMethodRef, int $amountCents, string $currency, string $idempotencyKey): ChargeResult;
}
