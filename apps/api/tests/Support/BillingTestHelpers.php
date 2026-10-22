<?php

namespace Tests\Support;

use App\Billing\PaymentGateway;
use App\Models\Tenant;
use Database\Seeders\CatalogSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BillingTestHelpers
{
    protected function seedBillingCatalog(): void
    {
        $this->seed(CatalogSeeder::class);
    }

    /**
     * Cria negócio, meio de pagamento e assinatura direto no banco (papel dono).
     *
     * @param  array<string, mixed>  $subscription  sobreposições da linha de assinatura
     * @return object{id: string, tenant_id: string, payment_method_id: string}
     */
    protected function subscription(array $subscription = [], string $methodRef = 'pm_fake_ok_x', ?string $slug = null): object
    {
        $db = DB::connection('pgsql_admin');
        $tenant = $this->makeTenant($slug ?? 'neg'.substr(md5((string) Str::uuid()), 0, 8));
        $planId = $db->table('plans')->where('key', 'profissional')->value('id');
        $methodId = (string) Str::uuid();
        $id = (string) Str::uuid();

        $db->table('payment_methods')->insert([
            'id' => $methodId, 'tenant_id' => $tenant->id, 'gateway_ref' => $methodRef,
            'type' => 'card', 'brand' => 'visa', 'last4' => '4242', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (($subscription['status'] ?? 'trialing') === 'active') {
            $subscription += ['current_period_start' => now()->subDays(10), 'current_period_end' => now()->addDays(20)];
        }

        $db->table('subscriptions')->insert($subscription + [
            'id' => $id, 'tenant_id' => $tenant->id, 'plan_id' => $planId, 'payment_method_id' => $methodId,
            'gateway_customer_ref' => 'cus_fake_x', 'cycle' => 'monthly', 'status' => 'trialing', 'price_cents' => 5000,
            'currency' => 'BRL', 'trial_starts_at' => now()->subDays(7), 'trial_ends_at' => now()->subHour(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (object) ['id' => $id, 'tenant_id' => $tenant->id, 'payment_method_id' => $methodId];
    }

    /** @return object{status: string, current_period_start: ?string, current_period_end: ?string, suspended_at: ?string} */
    protected function subscriptionRow(string $id): object
    {
        return DB::connection('pgsql_admin')->table('subscriptions')->where('id', $id)->first();
    }

    /** @return list<object> */
    protected function invoicesOf(string $subscriptionId): array
    {
        return DB::connection('pgsql_admin')->table('invoices')->where('subscription_id', $subscriptionId)->orderBy('period_start')->get()->all();
    }

    protected function at(string $moment): Carbon
    {
        return Carbon::parse($moment, 'UTC');
    }

    protected function gateway(): PaymentGateway
    {
        return app(PaymentGateway::class);
    }

    protected function tenantModel(string $id): Tenant
    {
        return Tenant::query()->findOrFail($id);
    }
}
