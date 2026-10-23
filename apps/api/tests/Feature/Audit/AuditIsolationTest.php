<?php

use App\Audit\AuditLog;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuditProbe;

beforeEach(function () {
    createAuditProbeTable();
    $this->a = $this->makeTenant('acme');
    $this->b = $this->makeTenant('beta');
});

afterEach(fn () => dropAuditProbeTable());

function enterAudit(object $tenant): void
{
    app(TenantContext::class)->set($tenant);
    app(TenantDatabaseContext::class)->apply($tenant->id);
}

it('CA-07 shows each business only its own history', function () {
    enterAudit($this->a);
    AuditProbe::create(['name' => 'da A']);
    enterAudit($this->b);
    AuditProbe::create(['name' => 'da B']);

    expect(AuditLog::query()->count())->toBe(1)
        ->and(AuditLog::query()->first()->after['name'])->toBe('da B');

    enterAudit($this->a);
    expect(AuditLog::query()->first()->after['name'])->toBe('da A');
    expect(auditRows())->toHaveCount(2);
});

it('CA-07 refuses a history row of another business at the database', function () {
    enterAudit($this->a);

    expect(fn () => DB::table('audit_logs')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->b->id, 'entity_type' => 'x', 'entity_id' => (string) Str::uuid(), 'action' => 'created', 'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('CA-06 does not let the API role edit or erase the history', function () {
    enterAudit($this->a);
    AuditProbe::create(['name' => 'a']);
    $id = auditRows()[0]->id;

    expect(fn () => DB::table('audit_logs')->where('id', $id)->update(['action' => 'deleted']))->toThrow(QueryException::class);
    expect(fn () => DB::table('audit_logs')->where('id', $id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::statement('TRUNCATE audit_logs'))->toThrow(QueryException::class);
    expect(auditRows())->toHaveCount(1);
});

it('CA-06 lets the platform role clean the history for the retention job', function () {
    enterAudit($this->a);
    AuditProbe::create(['name' => 'a']);

    $deleted = DB::connection('pgsql_platform')->transaction(function ($db) {
        $db->statement("select set_config('app.tenant_id', ?, true)", [$this->a->id]);

        return $db->table('audit_logs')->delete();
    });

    expect($deleted)->toBe(1);
});
