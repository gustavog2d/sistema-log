<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exclusão por sobreposição de faixas do expediente (e, depois, de agendamentos).
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('professionals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            // Pessoa do negócio que usa este perfil para entrar e ver a própria agenda.
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 100);
            $table->string('color', 7);
            $table->unsignedSmallInteger('commission_percent')->default(0);
            $table->string('status', 10)->default('active');
            $table->timestamps();
        });
        Schema::enableTenantRls('professionals');
        DB::statement('ALTER TABLE professionals ADD COLUMN name_key text GENERATED ALWAYS AS (lower(f_unaccent(name))) STORED');
        DB::statement('CREATE UNIQUE INDEX professionals_tenant_name_unique ON professionals (tenant_id, name_key)');
        DB::statement('CREATE UNIQUE INDEX professionals_tenant_user_unique ON professionals (tenant_id, user_id) WHERE user_id IS NOT NULL');
        DB::statement('CREATE INDEX professionals_tenant_order ON professionals (tenant_id, name_key, id)');
        DB::statement('CREATE UNIQUE INDEX professionals_tenant_id_unique ON professionals (tenant_id, id)');
        DB::statement("ALTER TABLE professionals ADD CONSTRAINT professionals_status_valid CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE professionals ADD CONSTRAINT professionals_commission_valid CHECK (commission_percent <= 100)');
        DB::statement("ALTER TABLE professionals ADD CONSTRAINT professionals_color_valid CHECK (color ~ '^#[0-9A-F]{6}\$')");

        // Expediente semanal: faixas em minutos desde a meia-noite (horário do negócio), sem sobreposição por profissional e dia.
        Schema::create('working_hours', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('professional_id');
            $table->unsignedSmallInteger('weekday');
            $table->unsignedSmallInteger('start_minute');
            $table->unsignedSmallInteger('end_minute');
            $table->timestamps();
        });
        Schema::enableTenantRls('working_hours');
        DB::statement('ALTER TABLE working_hours ADD CONSTRAINT working_hours_professional_fk FOREIGN KEY (tenant_id, professional_id) REFERENCES professionals (tenant_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE working_hours ADD CONSTRAINT working_hours_range_valid CHECK (weekday <= 6 AND start_minute < end_minute AND end_minute <= 1440 AND start_minute % 5 = 0 AND end_minute % 5 = 0)');
        DB::statement('ALTER TABLE working_hours ADD CONSTRAINT working_hours_no_overlap EXCLUDE USING gist (professional_id WITH =, weekday WITH =, int4range(start_minute, end_minute) WITH &&)');
        DB::statement('CREATE INDEX working_hours_tenant_professional ON working_hours (tenant_id, professional_id, weekday, start_minute)');

        // Folgas: de uma pessoa ou do negócio todo (professional_id vazio), em instantes.
        Schema::create('time_offs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('professional_id')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 100)->nullable();
            $table->timestamps();
        });
        Schema::enableTenantRls('time_offs');
        DB::statement('ALTER TABLE time_offs ADD CONSTRAINT time_offs_professional_fk FOREIGN KEY (tenant_id, professional_id) REFERENCES professionals (tenant_id, id) ON DELETE CASCADE');
        DB::statement("ALTER TABLE time_offs ADD CONSTRAINT time_offs_range_valid CHECK (ends_at > starts_at AND ends_at - starts_at <= interval '366 days')");
        DB::statement('CREATE INDEX time_offs_tenant_start ON time_offs (tenant_id, starts_at, id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('time_offs');
        Schema::dropIfExists('working_hours');
        Schema::dropIfExists('professionals');
    }
};
