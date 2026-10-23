<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Histórico de mudanças do negócio (FND-008): somente de acréscimo, com RLS.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name', 100)->nullable();
            // Acesso de suporte: quem da equipe da plataforma agiu como a pessoa (sem FK: o histórico sobrevive à equipe).
            $table->uuid('impersonated_by')->nullable();
            $table->string('entity_type', 40);
            $table->uuid('entity_id');
            $table->string('action', 10);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->timestamp('created_at', 6)->useCurrent();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'entity_type', 'entity_id', 'created_at']);
        });
        Schema::enableTenantRls('audit_logs');
        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT audit_logs_action_valid CHECK (action IN ('created', 'updated', 'deleted'))");

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    -- Registro só de acréscimo: a API nunca edita nem apaga o histórico.
                    REVOKE UPDATE, DELETE, TRUNCATE ON audit_logs FROM app_runtime;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
                    -- A limpeza por retenção (PLT-016) roda com o papel de plataforma.
                    GRANT SELECT, DELETE ON audit_logs TO app_platform;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
