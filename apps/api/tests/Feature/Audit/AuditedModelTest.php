<?php

use App\Tenancy\Exceptions\TenantContextMissing;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use App\TenantAuth\AuthenticatedPerson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuditProbe;
use Tests\Support\AuditProbeWithSecret;

beforeEach(function () {
    createAuditProbeTable();
    $this->tenant = $this->makeTenant('acme');
    app(TenantContext::class)->set($this->tenant);
    app(TenantDatabaseContext::class)->apply($this->tenant->id);
});

afterEach(fn () => dropAuditProbeTable());

function actAs(string $name = 'Ana Souza'): AuthenticatedPerson
{
    $id = (string) Str::uuid();
    DB::connection('pgsql_admin')->table('users')->insert(['id' => $id, 'name' => $name, 'email' => 'ana'.substr($id, 0, 6).'@exemplo.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    $person = new AuthenticatedPerson($id, $name, 'ana@exemplo.com', 'owner', 't', 'access', false);
    request()->attributes->set('tenant_person', $person);

    return $person;
}

it('CA-01 records the creation with the audited fields and no before', function () {
    $probe = AuditProbe::create(['name' => 'Tinta', 'price_cents' => 5000, 'note' => 'interno']);

    $rows = auditRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->action)->toBe('created')
        ->and($rows[0]->entity_type)->toBe('audit_probe')
        ->and($rows[0]->entity_id)->toBe($probe->id)
        ->and($rows[0]->before)->toBeNull()
        ->and($rows[0]->after)->toBe(['name' => 'Tinta', 'price_cents' => 5000]);
});

it('CA-02 records only the audited fields that changed, with before and after', function () {
    $probe = AuditProbe::create(['name' => 'Tinta', 'price_cents' => 5000]);

    $probe->update(['price_cents' => 6000, 'note' => 'mudou']);

    $rows = auditRows();
    expect($rows)->toHaveCount(2)
        ->and($rows[1]->action)->toBe('updated')
        ->and($rows[1]->before)->toBe(['price_cents' => 5000])
        ->and($rows[1]->after)->toBe(['price_cents' => 6000]);
});

it('CA-02 records nothing when only a field that is not audited changes', function () {
    $probe = AuditProbe::create(['name' => 'Tinta']);

    $probe->update(['note' => 'só uma nota']);
    $probe->update(['name' => 'Tinta']);

    expect(auditRows())->toHaveCount(1);
});

it('CA-03 records the deletion with the values before', function () {
    $probe = AuditProbe::create(['name' => 'Tinta', 'price_cents' => 5000]);

    $probe->delete();

    $rows = auditRows();
    expect($rows)->toHaveCount(2)
        ->and($rows[1]->action)->toBe('deleted')
        ->and($rows[1]->before)->toBe(['name' => 'Tinta', 'price_cents' => 5000])
        ->and($rows[1]->after)->toBeNull();
});

it('CA-04 refuses to audit a sensitive field and never stores its value', function () {
    expect(fn () => AuditProbeWithSecret::create(['name' => 'X', 'password' => 'segredo-123']))->toThrow(LogicException::class);

    expect(json_encode(auditRows()))->not->toContain('segredo-123');
});

it('CA-05 stores who did it, and nothing for internal processes', function () {
    $person = actAs('Ana Souza');
    AuditProbe::create(['name' => 'Feito pela Ana']);

    request()->attributes->remove('tenant_person');
    AuditProbe::create(['name' => 'Feito pelo sistema']);

    $rows = auditRows();
    expect($rows[0]->user_id)->toBe($person->id)
        ->and($rows[0]->actor_name)->toBe('Ana Souza')
        ->and($rows[1]->user_id)->toBeNull()
        ->and($rows[1]->actor_name)->toBeNull();
});

it('CA-05 keeps the name of a person that was removed later', function () {
    $user = (string) Str::uuid();
    DB::connection('pgsql_admin')->table('users')->insert(['id' => $user, 'name' => 'Saiu', 'email' => 'saiu@exemplo.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    request()->attributes->set('tenant_person', new AuthenticatedPerson($user, 'Saiu da Silva', 'saiu@exemplo.com', 'owner', 't', 'access', false));
    AuditProbe::create(['name' => 'Antes de sair']);

    DB::connection('pgsql_admin')->table('users')->where('id', $user)->delete();

    $row = auditRows()[0];
    expect($row->user_id)->toBeNull()->and($row->actor_name)->toBe('Saiu da Silva');
});

it('CA-08 leaves nothing behind when the change is rolled back', function () {
    try {
        DB::transaction(function () {
            AuditProbe::create(['name' => 'Some']);
            throw new RuntimeException('falhou depois');
        });
    } catch (RuntimeException) {
    }

    expect(auditRows())->toBe([])
        ->and(DB::connection('pgsql_admin')->table('audit_probes')->count())->toBe(0);
});

it('CA-08 does not keep the change when the audit write fails', function () {
    DB::connection('pgsql_admin')->statement('ALTER TABLE audit_logs RENAME TO audit_logs_x');

    try {
        expect(fn () => DB::transaction(fn () => AuditProbe::create(['name' => 'Some'])))->toThrow(QueryException::class);
        expect(DB::connection('pgsql_admin')->table('audit_probes')->count())->toBe(0);
    } finally {
        DB::connection('pgsql_admin')->statement('ALTER TABLE audit_logs_x RENAME TO audit_logs');
    }
});

it('CA-07 refuses to write without a business in context', function () {
    app(TenantContext::class)->clear();

    expect(fn () => AuditProbe::create(['name' => 'Sem negócio']))->toThrow(TenantContextMissing::class);
    expect(auditRows())->toBe([]);
});
