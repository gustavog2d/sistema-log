<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->string('name', 60);
            $table->timestamps();
        });
        Schema::enableTenantRls('expense_categories');
        // Chave de comparação do nome: sem acento e sem diferenciar maiúsculas (gerada pelo banco).
        DB::statement('ALTER TABLE expense_categories ADD COLUMN name_key text GENERATED ALWAYS AS (lower(f_unaccent(name))) STORED');
        DB::statement('CREATE UNIQUE INDEX expense_categories_tenant_name_unique ON expense_categories (tenant_id, name_key)');
        // Alvo da chave estrangeira composta: uma despesa só aponta para categoria do próprio negócio.
        DB::statement('CREATE UNIQUE INDEX expense_categories_tenant_id_unique ON expense_categories (tenant_id, id)');

        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->tenantId();
            $table->uuid('category_id');
            $table->string('description', 255);
            $table->unsignedInteger('amount_cents');
            $table->date('spent_on');
            $table->timestamps();
        });
        Schema::enableTenantRls('expenses');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_category_fk FOREIGN KEY (tenant_id, category_id) REFERENCES expense_categories (tenant_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amount_valid CHECK (amount_cents BETWEEN 1 AND 100000000)');
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_date_valid CHECK (spent_on BETWEEN DATE '2000-01-01' AND DATE '2099-12-31')");
        DB::statement('CREATE INDEX expenses_tenant_spent_on ON expenses (tenant_id, spent_on DESC, id)');
        DB::statement('CREATE INDEX expenses_tenant_category ON expenses (tenant_id, category_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
    }
};
