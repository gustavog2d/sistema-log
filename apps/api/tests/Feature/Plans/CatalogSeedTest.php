<?php

use App\Models\Feature;
use App\Models\Plan;
use Database\Seeders\CatalogSeeder;

it('CA-01 seeds the three launch plans active with the initial prices', function () {
    $this->seed(CatalogSeeder::class);

    $plans = Plan::orderBy('position')->get();

    expect($plans->pluck('key')->all())->toBe(['essencial', 'profissional', 'estudio']);
    expect($plans->pluck('name')->all())->toBe(['Essencial', 'Profissional', 'Estúdio']);
    expect($plans->pluck('status')->unique()->all())->toBe(['active']);
    expect($plans->pluck('monthly_price_cents')->all())->toBe([1500, 5000, 25000]);
    expect($plans->pluck('yearly_discount_percent')->unique()->all())->toBe([10]);
    expect($plans->map->yearlyPriceCents()->all())->toBe([16200, 54000, 270000]);
    expect($plans->pluck('trial_days')->unique()->all())->toBe([7]);
});

it('RN-05 seeds the planned features as enabled, unlimited and visible by default', function () {
    $this->seed(CatalogSeeder::class);

    $keys = Feature::pluck('key')->all();

    expect($keys)->toContain('agenda.publica', 'integracao.google_calendar', 'orcamento', 'relatorios.exportar', 'usuarios.max');
    expect(Feature::where('default_enabled', false)->count())->toBe(0);
    expect(Feature::whereNotNull('default_limit')->count())->toBe(0);
    expect(Feature::where('key', 'usuarios.max')->value('kind'))->toBe('limit');
});

it('RN-04 can run twice without duplicating or overwriting data', function () {
    $this->seed(CatalogSeeder::class);
    Plan::where('key', 'essencial')->update(['monthly_price_cents' => 4990]);

    $this->seed(CatalogSeeder::class);

    expect(Plan::count())->toBe(3)->and(Plan::where('key', 'essencial')->value('monthly_price_cents'))->toBe(4990);
});
