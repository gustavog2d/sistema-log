<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug', 30)->unique();
            $table->string('status', 20)->default('active');
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->string('currency', 3)->default('BRL');
            $table->timestamps();
        });

        // O slug segue a regra do produto também no banco (RN-02).
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_slug_format CHECK (slug ~ '^[a-z0-9]([a-z0-9-]{1,28}[a-z0-9])$')");

        // `tenants` não tem RLS: o papel de runtime só lê. Apagar um tenant derrubaria os dados dele
        // em cascata por cima do RLS, e trocar o slug sequestraria o subdomínio. Escritas de
        // plataforma (cadastro, suspensão) usam um papel próprio, a criar no PLT-003.
        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON tenants FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
