<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('niche', 40)->nullable()->after('slug');
        });

        // Identidade global de quem usa a plataforma como assinante (login no PLT-006).
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->string('terms_version', 40)->nullable();
            $table->timestamps();
        });

        // Vínculo da pessoa com um negócio e seu papel: dado de assinante, com RLS.
        Schema::create('memberships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->string('role', 20)->default('owner');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
        });
        Schema::enableTenantRls('memberships');

        // Meio de pagamento: só referência do gateway, bandeira e final. Nunca número de cartão.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('gateway_ref', 120);
            $table->string('type', 20)->default('card');
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->constrained('plans');
            $table->foreignUuid('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('gateway_customer_ref', 120)->nullable();
            $table->string('cycle', 10);
            $table->string('status', 20);
            $table->unsignedInteger('price_cents');
            $table->string('currency', 3)->default('BRL');
            $table->timestamp('trial_starts_at')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'trial_ends_at']);
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 40);
            $table->string('key', 255);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['scope', 'key']);
        });

        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_valid CHECK (status IN ('trialing', 'active', 'suspended', 'canceled'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_cycle_valid CHECK (cycle IN ('monthly', 'yearly'))");
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_role_valid CHECK (role IN ('owner', 'manager', 'professional', 'reception'))");

        // Dados de plataforma: o papel da API só lê. Quem escreve é o papel de plataforma.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON users, payment_methods, subscriptions, idempotency_keys FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('users');
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('niche'));
    }
};
