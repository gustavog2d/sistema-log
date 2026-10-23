<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exceções por assinante: dado da plataforma, escrito pelo super admin. A API só lê (para os limites).
        Schema::create('tenant_entitlement_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->index()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('feature_id')->constrained('features')->cascadeOnDelete();
            $table->boolean('enabled');
            $table->unsignedInteger('limit')->nullable();
            $table->boolean('visible')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('platform_users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'feature_id']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('suspended_reason', 200)->nullable();
        });

        // Acesso de suporte: o token de assinante guarda quem da equipe o pediu.
        Schema::table('user_access_tokens', function (Blueprint $table) {
            $table->foreignUuid('impersonated_by')->nullable()->constrained('platform_users')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON tenant_entitlement_overrides TO app_platform;
                    -- A gestão de assinantes cruza o negócio com o plano e o catálogo.
                    GRANT SELECT ON plans, features, plan_features TO app_platform;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON tenant_entitlement_overrides FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::table('user_access_tokens', fn (Blueprint $table) => $table->dropConstrainedForeignId('impersonated_by'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('suspended_reason'));
        Schema::dropIfExists('tenant_entitlement_overrides');
    }
};
