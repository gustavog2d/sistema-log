<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // A API mostra bandeira e final do meio de pagamento, mas nunca lê a tabela (tem a referência do gateway).
        // A visão devolve só as colunas seguras e só as linhas do negócio em contexto.
        DB::statement(<<<'SQL'
            CREATE VIEW payment_method_summaries WITH (security_barrier = true) AS
            SELECT id, tenant_id, type, brand, last4
            FROM payment_methods
            WHERE tenant_id = nullif(current_setting('app.tenant_id', true), '')::uuid
            SQL);

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    GRANT SELECT ON payment_method_summaries TO app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS payment_method_summaries');
    }
};
