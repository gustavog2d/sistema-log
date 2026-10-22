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
            return match (Config::string('billing.gateway')) {
                'fake' => new FakePaymentGateway,
                default => throw new InvalidArgumentException('Gateway de pagamento desconhecido: '.Config::string('billing.gateway')),
            };
        });
    }
}
