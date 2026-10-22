<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('canceled_at');
        });

        // Faturas: dado de assinante, com RLS. O papel de plataforma grava (informando o tenant na transação);
        // o papel da API só lê as do tenant em contexto.
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('subscription_id')->index()->constrained('subscriptions')->cascadeOnDelete();
            $table->timestamp('period_start');
            $table->timestamp('period_end');
            $table->unsignedInteger('amount_cents');
            $table->string('currency', 3)->default('BRL');
            $table->string('status', 10);
            $table->string('failure_reason', 60)->nullable();
            $table->string('gateway_charge_ref', 120)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['subscription_id', 'period_start']);
        });
        Schema::enableTenantRls('invoices');

        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_valid CHECK (status IN ('paid', 'failed'))");

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON invoices TO app_platform;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON invoices FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('suspended_at'));
    }
};
