<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Troca de plano agendada para o fim do período (downgrade): só colunas novas, nulas (compatível com a versão anterior).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignUuid('pending_plan_id')->nullable()->constrained('plans');
            $table->unsignedInteger('pending_price_cents')->nullable();
        });

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
                    -- A troca de plano confere o uso do limite de usuários: só as colunas de contagem, nunca o hash do token.
                    GRANT SELECT (id, tenant_id, expires_at) ON invitations TO app_platform;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pending_plan_id');
            $table->dropColumn('pending_price_cents');
        });
    }
};
