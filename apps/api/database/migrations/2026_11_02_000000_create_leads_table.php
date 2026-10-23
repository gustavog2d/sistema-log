<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Interessados vindos da landing: dado da plataforma (sem tenant). O IP é guardado só em hash.
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100);
            $table->string('email');
            $table->string('phone', 20)->nullable();
            $table->string('niche', 40)->nullable();
            $table->string('message', 500)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            $table->string('utm_term', 100)->nullable();
            $table->string('utm_content', 100)->nullable();
            $table->string('referrer', 255)->nullable();
            $table->string('landing_path', 255)->nullable();
            $table->string('status', 12)->default('new');
            $table->string('notes', 1000)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamp('privacy_accepted_at');
            $table->string('privacy_version', 40);
            $table->timestamps();
            $table->index(['email', 'created_at']);
            $table->index(['created_at', 'id']);
        });
        DB::statement("ALTER TABLE leads ADD CONSTRAINT leads_status_valid CHECK (status IN ('new', 'contacted', 'converted', 'discarded'))");

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
                    GRANT SELECT, INSERT, UPDATE, DELETE ON leads TO app_platform;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    -- Leads só passam pelo papel de plataforma (captura pública e tela da equipe).
                    REVOKE ALL ON leads FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
