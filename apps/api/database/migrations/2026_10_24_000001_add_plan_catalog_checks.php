<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // O banco também recusa valores fora da regra, mesmo que a validação seja contornada.
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_status_valid CHECK (status IN ('draft', 'active', 'archived'))");
        DB::statement('ALTER TABLE plans ADD CONSTRAINT plans_discount_range CHECK (yearly_discount_percent IS NULL OR yearly_discount_percent BETWEEN 0 AND 100)');
        DB::statement("ALTER TABLE plans ADD CONSTRAINT plans_active_has_price CHECK (status <> 'active' OR (monthly_price_cents IS NOT NULL AND yearly_discount_percent IS NOT NULL))");
        DB::statement("ALTER TABLE features ADD CONSTRAINT features_kind_valid CHECK (kind IN ('toggle', 'limit'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE features DROP CONSTRAINT features_kind_valid');
        DB::statement('ALTER TABLE plans DROP CONSTRAINT plans_active_has_price');
        DB::statement('ALTER TABLE plans DROP CONSTRAINT plans_discount_range');
        DB::statement('ALTER TABLE plans DROP CONSTRAINT plans_status_valid');
    }
};
