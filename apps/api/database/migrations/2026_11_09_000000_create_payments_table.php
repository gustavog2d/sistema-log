<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Comissão copiada do profissional ao agendar (mudar o profissional depois não altera o que já foi marcado).
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedSmallInteger('commission_percent')->default(0);
        });
        DB::statement('UPDATE appointments a SET commission_percent = p.commission_percent FROM professionals p WHERE p.id = a.professional_id AND p.tenant_id = a.tenant_id');
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_commission_valid CHECK (commission_percent <= 100)');
        DB::statement('CREATE UNIQUE INDEX appointments_tenant_id_unique ON appointments (tenant_id, id)');

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('appointment_id');
            $table->string('type', 12);
            $table->string('method', 12);
            $table->unsignedInteger('amount_cents');
            $table->timestampTz('paid_at');
            $table->string('status', 8)->default('received');
            $table->timestampTz('voided_at')->nullable();
            $table->timestamps();
        });
        Schema::enableTenantRls('payments');

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_appointment_fk FOREIGN KEY (tenant_id, appointment_id) REFERENCES appointments (tenant_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_type_valid CHECK (type IN ('deposit', 'installment', 'final'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_valid CHECK (method IN ('cash', 'pix', 'credit_card', 'debit_card', 'other'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_valid CHECK (status IN ('received', 'voided'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_valid CHECK (amount_cents BETWEEN 1 AND 100000000)');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_void_valid CHECK ((status = 'voided') = (voided_at IS NOT NULL))");
        DB::statement('CREATE INDEX payments_tenant_appointment ON payments (tenant_id, appointment_id)');
        DB::statement('CREATE INDEX payments_tenant_paid_at ON payments (tenant_id, paid_at)');

        // O total recebido nunca passa do valor combinado, mesmo com duas gravações ao mesmo tempo: o gatilho trava o
        // agendamento e soma o que já foi recebido (cada consulta enxerga o que a outra gravação já confirmou).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION payments_total_guard() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE
                total integer;
                received bigint;
            BEGIN
                IF NEW.status = 'received' THEN
                    SELECT value_total_cents INTO total FROM appointments WHERE id = NEW.appointment_id AND tenant_id = NEW.tenant_id FOR UPDATE;
                    SELECT COALESCE(SUM(amount_cents), 0) INTO received FROM payments
                        WHERE appointment_id = NEW.appointment_id AND tenant_id = NEW.tenant_id AND status = 'received' AND id <> NEW.id;
                    IF total IS NULL OR received + NEW.amount_cents > total THEN
                        RAISE EXCEPTION 'O total recebido passa do valor combinado do agendamento' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END $$
        SQL);
        DB::statement('CREATE TRIGGER payments_total_guard BEFORE INSERT OR UPDATE OF amount_cents, status, appointment_id ON payments FOR EACH ROW EXECUTE FUNCTION payments_total_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        DB::statement('DROP FUNCTION IF EXISTS payments_total_guard()');
        DB::statement('DROP INDEX IF EXISTS appointments_tenant_id_unique');
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS appointments_commission_valid');
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('commission_percent');
        });
    }
};
