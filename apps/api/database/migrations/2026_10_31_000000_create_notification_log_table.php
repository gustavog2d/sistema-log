<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Um registro por e-mail enviado, só para não enviar duas vezes o mesmo aviso. Não guarda o endereço.
        Schema::create('notification_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('dedupe_key', 160)->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
                    GRANT SELECT, INSERT, DELETE ON notification_log TO app_platform;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    REVOKE ALL ON notification_log FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
