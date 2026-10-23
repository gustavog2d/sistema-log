<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tabela de teste da auditoria (uuid, com RLS), criada com o papel dono. */
function createAuditProbeTable(): void
{
    Schema::connection('pgsql_admin')->dropIfExists('audit_probes');
    Schema::connection('pgsql_admin')->create('audit_probes', function (Blueprint $table) {
        $table->uuid('id')->primary();
        $table->tenantId();
        $table->string('name');
        $table->integer('price_cents')->default(0);
        $table->string('note')->nullable();
        $table->string('password')->nullable();
    });
    Schema::connection('pgsql_admin')->enableTenantRls('audit_probes');
}

function dropAuditProbeTable(): void
{
    Schema::connection('pgsql_admin')->dropIfExists('audit_probes');
}

/** @return list<object> registros do histórico (papel dono), do mais antigo ao mais novo */
function auditRows(?string $tenantId = null): array
{
    $query = DB::connection('pgsql_admin')->table('audit_logs')->orderBy('created_at')->orderBy('id');

    return ($tenantId === null ? $query : $query->where('tenant_id', $tenantId))->get()->map(function ($row) {
        $row->before = $row->before === null ? null : json_decode($row->before, true);
        $row->after = $row->after === null ? null : json_decode($row->after, true);

        return $row;
    })->all();
}
