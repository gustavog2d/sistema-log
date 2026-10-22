<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Datas obrigatórias: sem elas a assinatura nunca seria cobrada.
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_trial_has_end CHECK (status <> 'trialing' OR trial_ends_at IS NOT NULL)");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_active_has_period_end CHECK (status <> 'active' OR current_period_end IS NOT NULL)");

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
                    -- A cobrança só cria faturas e as lê: nunca altera nem apaga.
                    REVOKE UPDATE, DELETE, TRUNCATE ON invoices FROM app_platform;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    -- A API ainda não precisa ler meios de pagamento (referência do gateway em claro).
                    REVOKE ALL ON payment_methods FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT subscriptions_active_has_period_end');
        DB::statement('ALTER TABLE subscriptions DROP CONSTRAINT subscriptions_trial_has_end');
    }
};
