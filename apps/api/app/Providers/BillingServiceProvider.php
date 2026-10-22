<?php

namespace App\Providers;

use App\Billing\Fake\FakePaymentGateway;
use App\Billing\PaymentGateway;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGateway::class, function (): PaymentGateway {
            $configured = Config::get('billing.gateway');
            $driver = is_string($configured) ? $configured : '';

            // O meio de teste aprova qualquer pessoa sem cartão: nunca fora de desenvolvimento.
            if ($driver === 'fake' && ! $this->app->environment(['local', 'testing'])) {
                throw new InvalidArgumentException('O gateway de pagamento de teste só pode ser usado nos ambientes local e testing.');
            }

            return match ($driver) {
                'fake' => new FakePaymentGateway,
                default => throw new InvalidArgumentException('Defina BILLING_GATEWAY com um gateway de pagamento válido.'),
            };
        });
    }
}
