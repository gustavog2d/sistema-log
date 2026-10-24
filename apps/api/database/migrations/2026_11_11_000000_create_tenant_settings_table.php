<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurações simples do negócio (chave e valor em texto). A primeira é a meta mensal do financeiro (FIN-003).
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('key', 60);
            $table->text('value');
            $table->timestamps();
        });
        Schema::enableTenantRls('tenant_settings');
        DB::statement('CREATE UNIQUE INDEX tenant_settings_tenant_key_unique ON tenant_settings (tenant_id, key)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
