<?php

namespace App\Signup;

use App\Billing\PaymentGateway;
use App\Billing\PaymentMethodInfo;
use App\Billing\PaymentMethodRejected;
use App\Coupons\CouponService;
use App\Models\Plan;
use App\Models\User;
use App\Platform\Auth\PlatformAudit;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Cadastro do assinante: negócio, dona, vínculo, meio de pagamento e assinatura em teste, tudo em uma
 * transação do papel de plataforma. Nada é cobrado: a cobrança acontece no fim do teste (PLT-004).
 *
 * O gateway é chamado antes de gravar (assim nada fica no banco se o cartão for recusado) e, se a
 * gravação falhar depois, o cliente criado no gateway é removido (compensação).
 */
class SignupService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PlatformAudit $audit,
        private readonly SlugAvailability $availability,
        private readonly CouponService $coupons,
    ) {}

    /** @return array<string, mixed> */
    public function register(SignupData $data): array
    {
        $this->assertAvailable($data);

        $plan = Plan::query()->where('key', $data->plan)->where('status', 'active')->firstOrFail();
        $priceCents = $data->cycle === 'yearly' ? (int) $plan->yearlyPriceCents() : (int) $plan->monthly_price_cents;

        // O cupom é conferido antes de qualquer chamada ao gateway; o uso só é contado na gravação.
        $couponCode = $data->couponCode;
        $coupon = $couponCode === null ? null : $this->coupons->availableOrFail($couponCode, 'coupon_code');
        $originalCents = $priceCents;
        $priceCents = $coupon === null ? $priceCents : CouponService::priceAfter($priceCents, CouponService::percentOf($coupon));

        if ($priceCents > 0 && $data->paymentMethodToken === null) {
            throw ValidationException::withMessages(['payment_method_token' => 'Informe o meio de pagamento.']);
        }

        [$customerRef, $method] = $data->paymentMethodToken === null ? [null, null] : $this->attachPayment($data, $data->paymentMethodToken);

        // O hash (lento de propósito) é feito antes de abrir a transação, para não segurar conexão e linhas.
        $passwordHash = bcrypt($data->password);
        $ids = new SignupIds;
        $now = now();
        $trialEnds = $now->copy()->addDays($plan->trial_days);

        try {
            DB::connection('pgsql_platform')->transaction(
                fn (Connection $db) => $this->persist($db, $data, $passwordHash, $plan, $priceCents, $customerRef, $method, $ids, $now, $trialEnds, $coupon === null ? null : [$couponCode, $originalCents]),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Dois cadastros simultâneos com o mesmo endereço ou e-mail: o banco decide, a resposta é 422.
            $this->compensate($customerRef);

            throw ValidationException::withMessages(
                str_contains($e->getMessage(), 'users_email_unique') ? ['owner_email' => 'Este e-mail já está cadastrado.'] : ['slug' => 'Este endereço não está disponível.'],
            );
        } catch (Throwable $e) {
            $this->compensate($customerRef);

            throw $e;
        }

        // Sem senha nem token de pagamento: só ids e escolhas de negócio. Fora da transação de plataforma
        // porque a auditoria usa outra conexão e não participaria do rollback.
        $this->audit->record('signup.completed', null, [
            'tenant' => $ids->tenant, 'plan' => $plan->key, 'cycle' => $data->cycle, 'niche' => $data->niche,
        ]);

        if ($coupon !== null) {
            $this->coupons->recordRedeemed($coupon, $ids->tenant);
        }

        return $this->result($data, $plan, $priceCents, $method, $ids, $trialEnds, $coupon);
    }

    /** Endereço e e-mail livres (RN-05, RN-07). Em uma corrida, quem decide é o banco (restrição de unicidade). */
    protected function assertAvailable(SignupData $data): void
    {
        $slug = $this->availability->check($data->slug);

        if (! $slug['available']) {
            throw ValidationException::withMessages(['slug' => 'Este endereço não está disponível ('.($slug['reason'] ?? '').').']);
        }

        if (// Só colunas públicas: o papel da API não pode ler o hash das senhas.
            User::query()->select('id')->where('email', $data->ownerEmail)->exists()) {
            throw ValidationException::withMessages(['owner_email' => 'Este e-mail já está cadastrado.']);
        }
    }

    /**
     * Valida o meio de pagamento no gateway, sem cobrar.
     *
     * @return array{0: string, 1: PaymentMethodInfo}
     */
    private function attachPayment(SignupData $data, string $token): array
    {
        $customerRef = $this->gateway->createCustomer($data->ownerName, $data->ownerEmail);

        try {
            return [$customerRef, $this->gateway->attachPaymentMethod($customerRef, $token)];
        } catch (PaymentMethodRejected) {
            $this->compensate($customerRef);

            throw ValidationException::withMessages(['payment_method_token' => 'O meio de pagamento foi recusado.']);
        }
    }

    /** @param  array{0: string|null, 1: int}|null  $couponUse  código e preço original, quando há cupom */
    private function persist(Connection $db, SignupData $data, string $passwordHash, Plan $plan, int $priceCents, ?string $customerRef, ?PaymentMethodInfo $method, SignupIds $ids, Carbon $now, Carbon $trialEnds, ?array $couponUse): void
    {
        // Escopo da transação: a tabela de vínculos tem RLS e exige o tenant informado.
        $db->statement("select set_config('app.tenant_id', ?, true)", [$ids->tenant]);

        $db->table('tenants')->insert([
            'id' => $ids->tenant, 'name' => $data->businessName, 'slug' => $data->slug, 'niche' => $data->niche,
            'status' => 'active', 'timezone' => 'America/Sao_Paulo', 'currency' => $plan->currency,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('users')->insert([
            'id' => $ids->user, 'name' => $data->ownerName, 'email' => $data->ownerEmail,
            'password' => $passwordHash,
            'terms_accepted_at' => $now, 'terms_version' => Config::string('billing.terms_version'),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->table('memberships')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $ids->tenant, 'user_id' => $ids->user, 'role' => 'owner',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        if ($method !== null) {
            $db->table('payment_methods')->insert([
                'id' => $ids->method, 'tenant_id' => $ids->tenant, 'gateway_ref' => $method->ref,
                'type' => $method->type, 'brand' => $method->brand, 'last4' => $method->last4,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $db->table('subscriptions')->insert([
            'id' => $ids->subscription, 'tenant_id' => $ids->tenant, 'plan_id' => $plan->id,
            'payment_method_id' => $method === null ? null : $ids->method, 'gateway_customer_ref' => $customerRef,
            'cycle' => $data->cycle, 'status' => 'trialing', 'price_cents' => $priceCents, 'currency' => $plan->currency,
            'trial_starts_at' => $now, 'trial_ends_at' => $trialEnds,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        if ($couponUse !== null && $couponUse[0] !== null) {
            // Cupom travado na mesma transação: se o limite acabou entre a conferência e aqui, o cadastro inteiro volta atrás.
            $this->coupons->redeem($db, $couponUse[0], $ids->tenant, $ids->subscription, $couponUse[1], $priceCents);
        }
    }

    /** Remove o cliente do gateway (melhor esforço): a falha original é a que importa. */
    private function compensate(?string $customerRef): void
    {
        if ($customerRef === null) {
            return;
        }

        try {
            $this->gateway->deleteCustomer($customerRef);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** @return array<string, mixed> */
    private function result(SignupData $data, Plan $plan, int $priceCents, ?PaymentMethodInfo $method, SignupIds $ids, Carbon $trialEnds, ?\stdClass $coupon): array
    {
        return [
            'tenant' => ['id' => $ids->tenant, 'name' => $data->businessName, 'slug' => $data->slug, 'niche' => $data->niche],
            'subscription' => [
                'id' => $ids->subscription, 'status' => 'trialing', 'plan' => $plan->key, 'cycle' => $data->cycle,
                'price_cents' => $priceCents, 'currency' => $plan->currency,
                'trial_ends_at' => $trialEnds->toIso8601String(), 'first_charge_at' => $trialEnds->toIso8601String(),
            ],
            'payment_method' => $method === null ? null : ['type' => $method->type, 'brand' => $method->brand, 'last4' => $method->last4],
            'coupon' => $coupon === null ? null : ['code' => $coupon->code, 'percent_off' => CouponService::percentOf($coupon)],
        ];
    }
}
