<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('status', 10)->default('active');
        });
        DB::statement("ALTER TABLE memberships ADD CONSTRAINT memberships_status_valid CHECK (status IN ('active', 'inactive'))");

        // Convites pendentes: dado do negócio, com RLS. O aceite apaga a linha. Só o hash do token é guardado.
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('email');
            $table->string('name', 100);
            $table->string('role', 20);
            $table->string('token_hash', 64);
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['tenant_id', 'email']);
        });
        Schema::enableTenantRls('invitations');
        DB::statement("ALTER TABLE invitations ADD CONSTRAINT invitations_role_valid CHECK (role IN ('manager', 'professional', 'reception'))");

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_auth') THEN
                    -- A equipe é gerida com o papel de autenticação: cria pessoas e vínculos e mexe nos convites.
                    GRANT INSERT ON users TO app_auth;
                    GRANT INSERT, UPDATE ON memberships TO app_auth;
                    GRANT SELECT, INSERT, UPDATE, DELETE ON invitations TO app_auth;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    -- A API lista os convites, mas nunca vê o hash do token e não escreve.
                    REVOKE ALL ON invitations FROM app_runtime;
                    GRANT SELECT (id, tenant_id, email, name, role, invited_by, expires_at, created_at) ON invitations TO app_runtime;
                    REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON memberships FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        DB::statement('ALTER TABLE memberships DROP CONSTRAINT memberships_status_valid');
        Schema::table('memberships', fn (Blueprint $table) => $table->dropColumn('status'));
    }
};
