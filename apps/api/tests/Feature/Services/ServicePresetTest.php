<?php

use App\Models\Tenant;
use App\Services\Service;
use App\Services\ServiceCatalog;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seedBillingCatalog();
});

it('CA-07 creates the services and categories of the niche, without price', function (string $niche) {
    [$ws, $token] = ownerOf($this, 'neg');
    setNiche($ws, $niche);

    $result = servicesApi($this, $token, 'POST', 'neg', 'services/presets')->assertOk()->assertJsonPath('niche', $niche);

    $db = DB::connection('pgsql_admin');
    expect($result->json('created.services'))->toBeGreaterThan(2)->and($result->json('created.categories'))->toBeGreaterThan(0)
        ->and($result->json('skipped'))->toBe(['categories' => 0, 'services' => 0])
        ->and($db->table('services')->count())->toBe($result->json('created.services'))
        ->and($db->table('services')->whereNotNull('price_cents')->count())->toBe(0)
        ->and($db->table('services')->where('status', 'active')->count())->toBe($result->json('created.services'))
        ->and($db->table('services')->whereNull('category_id')->count())->toBe(0);

    // Duração dentro das regras do cadastro.
    foreach ($db->table('services')->pluck('duration_minutes') as $minutes) {
        expect($minutes % 5)->toBe(0)->and($minutes)->toBeGreaterThanOrEqual(5)->toBeLessThanOrEqual(720);
    }
})->with(['tatuagem', 'manicure', 'barbearia', 'estetica_automotiva']);

it('CA-07 skips what already exists by name and so does a second call', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    setNiche($ws, 'manicure');
    $first = servicesApi($this, $token, 'POST', 'neg', 'services/presets')->assertOk();
    $total = DB::connection('pgsql_admin')->table('services')->count();

    $second = servicesApi($this, $token, 'POST', 'neg', 'services/presets')->assertOk();

    expect($second->json('created'))->toBe(['categories' => 0, 'services' => 0])
        ->and($second->json('skipped'))->toBe(['categories' => $first->json('created.categories'), 'services' => $first->json('created.services')])
        ->and(DB::connection('pgsql_admin')->table('services')->count())->toBe($total);
});

it('CA-07 keeps what the owner already had, with the same name in another spelling, and does not touch its price', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    setNiche($ws, 'barbearia');
    $mine = seedService($ws, ['name' => 'CORTE', 'price_cents' => 4500, 'duration_minutes' => 30]);

    $result = servicesApi($this, $token, 'POST', 'neg', 'services/presets')->assertOk();

    expect($result->json('skipped.services'))->toBeGreaterThanOrEqual(1)
        ->and(DB::connection('pgsql_admin')->table('services')->where('id', $mine->id)->value('price_cents'))->toBe(4500)
        ->and(DB::connection('pgsql_admin')->table('services')->whereRaw("lower(name) = 'corte'")->count())->toBe(1);
});

it('CA-07 refuses a business without a niche with a preset', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    setNiche($ws, null);

    servicesApi($this, $token, 'POST', 'neg', 'services/presets')->assertStatus(422);
    expect(DB::connection('pgsql_admin')->table('services')->count())->toBe(0);
});

it('CA-07 creates the preset in only the business that asked', function () {
    [$a, $tokenA] = ownerOf($this, 'negocioa');
    [$b] = ownerOf($this, 'negociob');
    setNiche($a, 'tatuagem');

    servicesApi($this, $tokenA, 'POST', 'negocioa', 'services/presets')->assertOk();

    expect(DB::connection('pgsql_admin')->table('services')->where('tenant_id', $b->tenant_id)->count())->toBe(0);
});

it('CA-08 records each created service and category in the history', function () {
    [$ws, $token] = ownerOf($this, 'neg');
    setNiche($ws, 'tatuagem');

    $result = servicesApi($this, $token, 'POST', 'neg', 'services/presets')->assertOk();

    $rows = collect(auditRows());
    expect($rows->where('entity_type', 'service')->count())->toBe($result->json('created.services'))
        ->and($rows->where('entity_type', 'service_category')->count())->toBe($result->json('created.categories'))
        ->and($rows->every(fn ($r) => $r->action === 'created'))->toBeTrue();
});

it('CA-07 recovers when another call created the same services at the same time', function () {
    [$ws] = ownerOf($this, 'neg');
    setNiche($ws, 'barbearia');
    app(TenantContext::class)->set(Tenant::query()->findOrFail($ws->tenant_id));
    app(TenantDatabaseContext::class)->apply($ws->tenant_id);
    $inserted = false;

    // Entre a checagem "já existe?" e a gravação, outra chamada cria o serviço: o índice único recusa e o preset recomeça.
    Service::creating(function (Service $service) use (&$inserted, $ws) {
        if (! $inserted && $service->name === 'Barba') {
            $inserted = true;
            DB::connection('pgsql_admin')->table('services')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $ws->tenant_id, 'name' => 'Barba', 'duration_minutes' => 30, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $result = app(ServiceCatalog::class)->applyPreset();

    expect($inserted)->toBeTrue()
        ->and($result['skipped']['services'])->toBeGreaterThanOrEqual(1)
        ->and(DB::connection('pgsql_admin')->table('services')->whereRaw("lower(name) = 'barba'")->count())->toBe(1);
});
