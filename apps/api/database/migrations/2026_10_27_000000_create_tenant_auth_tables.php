<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            // Tokens emitidos antes deste instante não valem mais (trocar senha, sair de todos os acessos).
            $table->timestamp('tokens_valid_after', 6)->nullable();
            $table->text('totp_secret')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();
            $table->unsignedBigInteger('totp_last_timestamp')->nullable();
            $table->text('recovery_codes')->nullable();
        });

        // Tokens de acesso e de desafio do assinante: dado do negócio, com RLS. Só o hash do segredo é guardado.
        Schema::create('user_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->foreignUuid('user_id')->index()->constrained('users')->cascadeOnDelete();
            $table->string('kind', 12)->default('access');
            $table->string('token_hash', 64);
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at', 6);
        });
        Schema::enableTenantRls('user_access_tokens');
        DB::statement("ALTER TABLE user_access_tokens ADD CONSTRAINT user_access_tokens_kind_valid CHECK (kind IN ('access', 'challenge', 'setup'))");

        // Recuperação de senha: global (a pessoa é global); token em hash, uso único.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token_hash', 64);
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_auth') THEN
                    -- A autenticação lê e atualiza a pessoa, lê os vínculos (RLS) e emite tokens.
                    GRANT SELECT, UPDATE ON users TO app_auth;
                    GRANT SELECT ON memberships, tenants TO app_auth;
                    GRANT SELECT, INSERT, UPDATE, DELETE ON user_access_tokens TO app_auth;
                    GRANT SELECT, INSERT, UPDATE, DELETE ON password_reset_tokens TO app_auth;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    -- A API valida e revoga tokens (só os do negócio em contexto) e atualiza o último uso.
                    REVOKE ALL ON user_access_tokens FROM app_runtime;
                    GRANT SELECT, DELETE ON user_access_tokens TO app_runtime;
                    GRANT UPDATE (last_used_at) ON user_access_tokens TO app_runtime;
                    REVOKE ALL ON password_reset_tokens FROM app_runtime;
                    -- Dos novos campos de users, a API só lê os que não são segredo.
                    GRANT SELECT (tokens_valid_after, totp_confirmed_at) ON users TO app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('user_access_tokens');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'failed_attempts', 'locked_until', 'last_login_at', 'tokens_valid_after',
            'totp_secret', 'totp_confirmed_at', 'totp_last_timestamp', 'recovery_codes',
        ]));
    }
};
