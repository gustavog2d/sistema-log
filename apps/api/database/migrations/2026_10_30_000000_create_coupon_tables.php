<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cupons são dado da plataforma (sem tenant). Código sempre em maiúsculas.
        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->unsignedSmallInteger('percent_off');
            $table->string('status', 10)->default('active');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redeemed_count')->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->string('description', 200)->nullable();
            $table->timestamps();
        });
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_percent_valid CHECK (percent_off BETWEEN 1 AND 100)');
        DB::statement("ALTER TABLE coupons ADD CONSTRAINT coupons_status_valid CHECK (status IN ('active', 'inactive'))");
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_limit_valid CHECK (max_redemptions IS NULL OR (max_redemptions >= 1 AND redeemed_count <= max_redemptions))');
        DB::statement('ALTER TABLE coupons ADD CONSTRAINT coupons_code_upper CHECK (code = upper(code))');

        // Cada uso: qual cupom, qual negócio, preço original e final. Um negócio só usa um cupom.
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('coupon_id')->index()->constrained('coupons');
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->unsignedSmallInteger('percent_off');
            $table->unsignedInteger('original_price_cents');
            $table->unsignedInteger('final_price_cents');
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_platform') THEN
                    GRANT SELECT, INSERT, UPDATE ON coupons TO app_platform;
                    GRANT SELECT, INSERT ON coupon_redemptions TO app_platform;
                END IF;
                IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_runtime') THEN
                    -- A API só lê cupons (consulta pública do preço); quem escreve é o papel de plataforma.
                    REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON coupons FROM app_runtime;
                    -- Os registros de uso ligam cupom e negócio: nada disso é da API.
                    REVOKE ALL ON coupon_redemptions FROM app_runtime;
                END IF;
            END
            $$
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
