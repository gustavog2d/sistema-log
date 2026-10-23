<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Busca sem acento e sem diferenciar maiúsculas: `unaccent` embrulhada em função IMMUTABLE (exigência de colunas geradas).
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement("CREATE OR REPLACE FUNCTION f_unaccent(text) RETURNS text AS \$\$ SELECT public.unaccent('public.unaccent', \$1) \$\$ LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT");

        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 100);
            $table->string('phone', 16);
            $table->string('email', 255)->nullable();
            $table->string('instagram', 30)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::enableTenantRls('clients');

        // Colunas de busca e de ordenação (geradas pelo banco, sempre em sincronia).
        DB::statement('ALTER TABLE clients ADD COLUMN search_name text GENERATED ALWAYS AS (lower(f_unaccent(name))) STORED');
        DB::statement("ALTER TABLE clients ADD COLUMN search_text text GENERATED ALWAYS AS (lower(f_unaccent(name || ' ' || coalesce(email, '') || ' ' || coalesce(instagram, '')))) STORED");
        DB::statement("ALTER TABLE clients ADD COLUMN phone_digits text GENERATED ALWAYS AS (regexp_replace(phone, '[^0-9]', '', 'g')) STORED");

        DB::statement('CREATE UNIQUE INDEX clients_tenant_phone_unique ON clients (tenant_id, phone)');
        DB::statement('CREATE UNIQUE INDEX clients_tenant_email_unique ON clients (tenant_id, email) WHERE email IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX clients_tenant_instagram_unique ON clients (tenant_id, instagram) WHERE instagram IS NOT NULL');
        DB::statement('CREATE INDEX clients_tenant_order ON clients (tenant_id, search_name, id)');
        DB::statement('CREATE INDEX clients_search_text_trgm ON clients USING gin (search_text gin_trgm_ops)');
        DB::statement('CREATE INDEX clients_phone_digits_trgm ON clients USING gin (phone_digits gin_trgm_ops)');
        DB::statement("ALTER TABLE clients ADD CONSTRAINT clients_phone_e164 CHECK (phone ~ '^\\+[1-9][0-9]{7,14}\$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
