<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo de planos e funcionalidades: tabelas de plataforma (sem tenant_id nem RLS).
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 100);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 20)->default('draft');
            $table->unsignedSmallInteger('trial_days')->default(7);
            $table->string('currency', 3)->default('BRL');
            $table->unsignedInteger('monthly_price_cents')->nullable();
            $table->unsignedTinyInteger('yearly_discount_percent')->nullable();
            $table->timestamps();
        });

        Schema::create('features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 60)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('kind', 10)->default('toggle');
            $table->boolean('default_enabled')->default(true);
            $table->unsignedInteger('default_limit')->nullable();
            $table->boolean('default_visible')->default(true);
            $table->timestamps();
        });

        Schema::create('plan_features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignUuid('feature_id')->constrained('features')->cascadeOnDelete();
            $table->boolean('enabled');
            $table->unsignedInteger('limit')->nullable();
            $table->boolean('visible')->default(true);
            $table->timestamps();
            $table->unique(['plan_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('features');
        Schema::dropIfExists('plans');
    }
};
