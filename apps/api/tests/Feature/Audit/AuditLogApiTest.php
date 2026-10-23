<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

/** Cria registros direto no banco (papel dono) para o negócio. */
function seedLogs(object $workspace, int $count, array $override = []): array
{
    $ids = [];

    for ($i = 0; $i < $count; $i++) {
        $ids[] = $id = (string) Str::uuid();
        DB::connection('pgsql_admin')->table('audit_logs')->insert($override + [
            'id' => $id, 'tenant_id' => $workspace->tenant_id, 'user_id' => $workspace->user_id, 'actor_name' => 'Pessoa owner',
            'entity_type' => 'client', 'entity_id' => (string) Str::uuid(), 'action' => 'updated',
            'before' => json_encode(['name' => 'A']), 'after' => json_encode(['name' => 'B']), 'created_at' => Carbon::parse('2026-11-01 10:00:00')->addMinutes($i),
        ]);
    }

    return $ids;
}

function auditApi($test, string $token, string $slug, string $query = ''): TestResponse
{
    return manage($test, $token, 'GET', $slug, 'audit-logs'.$query);
}

it('CA-09 lists newest first with a cursor and limit', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $ids = seedLogs($ws, 5);

    $first = auditApi($this, $token, 'neg', '?limit=2')->assertOk();
    expect(collect($first->json('data'))->pluck('id')->all())->toBe([$ids[4], $ids[3]])->and($first->json('next_cursor'))->not->toBeNull();

    $second = auditApi($this, $token, 'neg', '?limit=2&cursor='.urlencode($first->json('next_cursor')))->assertOk();
    expect(collect($second->json('data'))->pluck('id')->all())->toBe([$ids[2], $ids[1]]);
});

it('CA-09 filters by entity type and entity id', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedLogs($ws, 2, ['entity_type' => 'client']);
    $entity = (string) Str::uuid();
    seedLogs($ws, 1, ['entity_type' => 'expense', 'entity_id' => $entity]);

    expect(auditApi($this, $token, 'neg', '?entity_type=client')->json('data'))->toHaveCount(2);
    $one = auditApi($this, $token, 'neg', "?entity_type=expense&entity_id={$entity}")->assertOk()->json('data');
    expect($one)->toHaveCount(1)->and($one[0]['entity_id'])->toBe($entity);
});

it('CA-09 refuses an invalid limit, entity id and entity type', function (string $query, string $field) {
    [, $token] = ownerOf($this, 'neg');

    auditApi($this, $token, 'neg', $query)->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    ['?limit=0', 'limit'], ['?limit=51', 'limit'], ['?entity_id=nao-e-uuid', 'entity_id'], ['?entity_type=Cliente%20X', 'entity_type'],
]);

it('CA-09 breaks ties by id and starts over when the cursor is not valid', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    $ids = seedLogs($ws, 3, ['created_at' => '2026-11-01 10:00:00']);
    sort($ids);

    $first = auditApi($this, $token, 'neg', '?limit=2')->assertOk();
    $second = auditApi($this, $token, 'neg', '?limit=2&cursor='.urlencode($first->json('next_cursor')))->assertOk();

    expect(collect($first->json('data'))->pluck('id')->merge(collect($second->json('data'))->pluck('id'))->all())->toBe(array_reverse($ids));
    expect(auditApi($this, $token, 'neg', '?limit=2&cursor=lixo')->assertOk()->json('data'))->toHaveCount(2);
});

it('CA-09 shows who acted in a support access', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedLogs($ws, 1, ['impersonated_by' => (string) Str::uuid()]);

    expect(auditApi($this, $token, 'neg')->json('data.0.actor.support'))->toBeTrue();
});

it('CA-09 shows only the history of the business of the host', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    seedLogs($a, 1);
    seedLogs($b, 3);

    expect(auditApi($this, $tokenA, 'negocioa')->json('data'))->toHaveCount(1);
});

it('CA-10 lets owner and manager read, and refuses professional and reception', function (string $role, int $status) {
    $this->workspace('neg', 'dona@exemplo.com');
    $this->workspace('neg2', 'papel@exemplo.com', $role);
    $token = $this->tokenFor('neg2', 'papel@exemplo.com');

    auditApi($this, $token, 'neg2')->assertStatus($status);
})->with([['owner', 200], ['manager', 200], ['professional', 403], ['reception', 403]]);

it('CA-10 asks for a login and also works with a suspended subscription', function () {
    [$ws, $token] = ownerOf($this, 'neg', 'suspended', ['suspended_at' => now()->subDay()]);
    seedLogs($ws, 1);

    $this->getJson('http://neg.app.test/api/v1/audit-logs')->assertUnauthorized();
    auditApi($this, $token, 'neg')->assertOk();
});

it('CA-12 returns bodies that respect the openapi contract', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    seedLogs($ws, 2);
    seedLogs($ws, 1, ['user_id' => null, 'actor_name' => null, 'action' => 'created', 'before' => null]);
    $this->loadOpenApi('FND-008-auditoria-do-assinante');

    $response = auditApi($this, $token, 'neg')->assertOk();

    expect($this->schemaErrors($response->json(), 'AuditLogPage'))->toBe([]);
    expect(collect($response->json('data'))->firstWhere('action', 'created')['actor'])->toBeNull();
});
