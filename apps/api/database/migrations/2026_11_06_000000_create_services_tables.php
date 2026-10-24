<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 60);
            $table->timestamps();
        });
        Schema::enableTenantRls('service_categories');
        // Chave de comparação do nome: sem acento e sem diferenciar maiúsculas (gerada pelo banco).
        DB::statement('ALTER TABLE service_categories ADD COLUMN name_key text GENERATED ALWAYS AS (lower(f_unaccent(name))) STORED');
        DB::statement('CREATE UNIQUE INDEX service_categories_tenant_name_unique ON service_categories (tenant_id, name_key)');
        // Alvo da chave estrangeira composta: um serviço só aponta para categoria do próprio negócio.
        DB::statement('CREATE UNIQUE INDEX service_categories_tenant_id_unique ON service_categories (tenant_id, id)');

        Schema::create('services', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('category_id')->nullable();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            // Vazio = "sob orçamento".
            $table->unsignedInteger('price_cents')->nullable();
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->boolean('requires_deposit')->default(false);
            $table->string('status', 10)->default('active');
            $table->timestamps();
        });
        Schema::enableTenantRls('services');
        DB::statement('ALTER TABLE services ADD COLUMN name_key text GENERATED ALWAYS AS (lower(f_unaccent(name))) STORED');
        DB::statement('CREATE UNIQUE INDEX services_tenant_name_unique ON services (tenant_id, name_key)');
        DB::statement('CREATE INDEX services_tenant_order ON services (tenant_id, name_key, id)');
        DB::statement('CREATE INDEX services_tenant_category ON services (tenant_id, category_id)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_category_fk FOREIGN KEY (tenant_id, category_id) REFERENCES service_categories (tenant_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE services ADD CONSTRAINT services_status_valid CHECK (status IN ('active', 'archived'))");
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_duration_valid CHECK (duration_minutes BETWEEN 5 AND 720 AND duration_minutes % 5 = 0)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_buffers_valid CHECK (buffer_before_minutes <= 240 AND buffer_after_minutes <= 240 AND buffer_before_minutes % 5 = 0 AND buffer_after_minutes % 5 = 0)');
        DB::statement('ALTER TABLE services ADD CONSTRAINT services_price_valid CHECK (price_cents IS NULL OR price_cents <= 100000000)');
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
        Schema::dropIfExists('service_categories');
    }
};
