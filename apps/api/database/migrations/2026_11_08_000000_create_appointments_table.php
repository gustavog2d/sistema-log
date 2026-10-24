<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Alvos das chaves estrangeiras compostas: um agendamento só aponta para cadastros do próprio negócio.
        DB::statement('CREATE UNIQUE INDEX clients_tenant_id_unique ON clients (tenant_id, id)');
        DB::statement('CREATE UNIQUE INDEX services_tenant_id_unique ON services (tenant_id, id)');

        Schema::create('appointments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('client_id');
            $table->uuid('professional_id');
            $table->uuid('service_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            // Intervalos do serviço no momento do agendamento (mudar o serviço depois não muda o que já foi marcado).
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            // Faixa que o agendamento ocupa de fato (início menos o intervalo antes até o fim mais o intervalo depois).
            $table->timestampTz('blocked_from');
            $table->timestampTz('blocked_until');
            $table->string('status', 10)->default('scheduled');
            $table->string('type', 12)->default('appointment');
            $table->string('origin', 12)->default('panel');
            $table->unsignedSmallInteger('session_number')->nullable();
            $table->unsignedSmallInteger('sessions_total')->nullable();
            $table->unsignedInteger('value_total_cents')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('client_review')->nullable();
            $table->timestamps();
        });
        Schema::enableTenantRls('appointments');

        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_client_fk FOREIGN KEY (tenant_id, client_id) REFERENCES clients (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_professional_fk FOREIGN KEY (tenant_id, professional_id) REFERENCES professionals (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_service_fk FOREIGN KEY (tenant_id, service_id) REFERENCES services (tenant_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_valid CHECK (status IN ('pending', 'scheduled', 'completed', 'canceled', 'no_show'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_type_valid CHECK (type IN ('appointment', 'retouch'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_origin_valid CHECK (origin IN ('panel', 'public_link', 'app'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_range_valid CHECK (ends_at > starts_at AND ends_at - starts_at <= interval '24 hours' AND blocked_from <= starts_at AND blocked_until >= ends_at)");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_session_valid CHECK (session_number IS NULL OR sessions_total IS NULL OR session_number <= sessions_total)');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_review_valid CHECK (client_review IS NULL OR (client_review BETWEEN 1 AND 5 AND status = 'completed'))");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_value_valid CHECK (value_total_cents IS NULL OR value_total_cents <= 100000000)');
        // Nunca dois agendamentos ativos do mesmo profissional ocupando o mesmo horário (com os intervalos), nem em uma corrida.
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_no_overlap EXCLUDE USING gist (professional_id WITH =, tstzrange(blocked_from, blocked_until) WITH &&) WHERE (status IN ('pending', 'scheduled', 'completed'))");
        DB::statement('CREATE INDEX appointments_tenant_start ON appointments (tenant_id, starts_at)');
        DB::statement('CREATE INDEX appointments_tenant_professional ON appointments (tenant_id, professional_id, starts_at)');
        DB::statement('CREATE INDEX appointments_tenant_client ON appointments (tenant_id, client_id)');
        DB::statement('CREATE INDEX appointments_tenant_service ON appointments (tenant_id, service_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        DB::statement('DROP INDEX IF EXISTS services_tenant_id_unique');
        DB::statement('DROP INDEX IF EXISTS clients_tenant_id_unique');
    }
};
