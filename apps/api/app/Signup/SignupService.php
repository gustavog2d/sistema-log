<?php

namespace App\Signup;

use App\Billing\PaymentGateway;
use App\Billing\PaymentMethodRejected;
use App\Models\Plan;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro do assinante: negócio, dona, vínculo, meio de pagamento e assinatura em teste, tudo em uma
 * transação do papel de plataforma. Nada é cobrado: a cobrança acontece no fim do teste (PLT-004).
 */
class SignupService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PlatformAudit $audit,
    ) {}

    /** @return array<string, mixed> */
    public function register(SignupData $data): array
    {
        $plan = Plan::query()->where('key', $data->plan)->where('status', 'active')->firstOrFail();
        $cycle = $data->cycle;
        $priceCents = $cycle === 'yearly' ? (int) $plan->yearlyPriceCents() : (int) $plan->monthly_price_cents;
        $email = $data->ownerEmail;

        // O meio de pagamento é validado antes de gravar qualquer coisa; nada é cobrado.
        $customerRef = $this->gateway->createCustomer($data->ownerName, $email);
        try {
            $method = $this->gateway->attachPaymentMethod($customerRef, $data->paymentMethodToken);
        } catch (PaymentMethodRejected) {
            throw ValidationException::withMessages(['payment_method_token' => 'O meio de pagamento foi recusado.']);
        }

        $now = now();
        $trialEnds = $now->copy()->addDays($plan->trial_days);
        $ids = ['tenant' => (string) Str::uuid(), 'user' => (string) Str::uuid(), 'method' => (string) Str::uuid(), 'subscription' => (string) Str::uuid()];

        DB::connection('pgsql_platform')->transaction(function ($db) use ($data, $plan, $cycle, $priceCents, $email, $customerRef, $method, $now, $trialEnds, $ids): void {
            // Escopo da transação: a tabela de vínculos tem RLS e exige o tenant informado.
            $db->statement("select set_config('app.tenant_id', ?, true)", [$ids['tenant']]);

            $db->table('tenants')->insert([
                'id' => $ids['tenant'], 'name' => $data->businessName, 'slug' => $data->slug, 'niche' => $data->niche,
                'status' => 'active', 'timezone' => 'America/Sao_Paulo', 'currency' => $plan->currency,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('users')->insert([
                'id' => $ids['user'], 'name' => $data->ownerName, 'email' => $email,
                'password' => bcrypt($data->password),
                'terms_accepted_at' => $now, 'terms_version' => Config::string('billing.terms_version'),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('memberships')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $ids['tenant'], 'user_id' => $ids['user'], 'role' => 'owner',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('payment_methods')->insert([
                'id' => $ids['method'], 'tenant_id' => $ids['tenant'], 'gateway_ref' => $method->ref,
                'type' => $method->type, 'brand' => $method->brand, 'last4' => $method->last4,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('subscriptions')->insert([
                'id' => $ids['subscription'], 'tenant_id' => $ids['tenant'], 'plan_id' => $plan->id,
                'payment_method_id' => $ids['method'], 'gateway_customer_ref' => $customerRef,
                'cycle' => $cycle, 'status' => 'trialing', 'price_cents' => $priceCents, 'currency' => $plan->currency,
                'trial_starts_at' => $now, 'trial_ends_at' => $trialEnds,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            // Sem senha nem token de pagamento: só ids e escolhas de negócio.
            $this->audit->record('signup.completed', null, [
                'tenant' => $ids['tenant'], 'plan' => $plan->key, 'cycle' => $cycle, 'niche' => $data->niche,
            ]);
        });

        return [
            'tenant' => ['id' => $ids['tenant'], 'name' => $data->businessName, 'slug' => $data->slug, 'niche' => $data->niche],
            'subscription' => [
                'id' => $ids['subscription'], 'status' => 'trialing', 'plan' => $plan->key, 'cycle' => $cycle,
                'price_cents' => $priceCents, 'currency' => $plan->currency,
                'trial_ends_at' => $trialEnds->toIso8601String(), 'first_charge_at' => $trialEnds->toIso8601String(),
            ],
            'payment_method' => ['type' => $method->type, 'brand' => $method->brand, 'last4' => $method->last4],
        ];
    }
}
