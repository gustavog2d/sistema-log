<?php

use Database\Factories\FeatureFactory;
use Database\Factories\PlanFactory;
use Illuminate\Support\Facades\DB;

function auditEvents(): array
{
    return DB::connection('pgsql_admin')->table('platform_audit_logs')->get()->all();
}

it('CA-13 records the plan change with before and after', function () {
    $plan = PlanFactory::new()->create(['monthly_price_cents' => 5000]);

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", ['monthly_price_cents' => 6000, 'name' => 'Novo'])->assertOk();

    $event = collect(auditEvents())->firstWhere('event', 'plan.updated');
    $metadata = json_decode($event->metadata, true);

    expect($metadata['plan'])->toBe($plan->id);
    expect($metadata['before'])->toMatchArray(['monthly_price_cents' => 5000]);
    expect($metadata['after'])->toMatchArray(['monthly_price_cents' => 6000, 'name' => 'Novo']);
});

it('CA-13 records creation and rule changes', function () {
    $token = $this->adminToken();
    $plan = $this->asToken($token, 'POST', 'plans', ['key' => 'novo', 'name' => 'Novo', 'position' => 1])->json('id');
    $feature = FeatureFactory::new()->create();
    $this->asToken($token, 'PUT', "plans/{$plan}/features/{$feature->id}", ['enabled' => false, 'visible' => true]);
    $this->asToken($token, 'DELETE', "plans/{$plan}/features/{$feature->id}");

    expect(collect(auditEvents())->pluck('event')->all())->toContain('plan.created', 'plan_feature.set', 'plan_feature.reset');
});

it('CA-13 does not audit failed validations', function () {
    $plan = PlanFactory::new()->create();

    $this->asToken($this->adminToken(), 'PATCH', "plans/{$plan->id}", ['status' => 'active'])->assertStatus(422);

    expect(collect(auditEvents())->pluck('event')->all())->not->toContain('plan.updated');
});

it('CA-13 records feature creation and update with before and after', function () {
    $token = $this->adminToken();
    $id = $this->asToken($token, 'POST', 'features', [
        'key' => 'nova.funcao', 'name' => 'Nova', 'kind' => 'toggle', 'default_enabled' => true, 'default_visible' => true,
    ])->json('id');
    $this->asToken($token, 'PATCH', "features/{$id}", ['name' => 'Nova função']);

    $events = collect(auditEvents());
    $created = json_decode($events->firstWhere('event', 'feature.created')->metadata, true);
    $updated = json_decode($events->firstWhere('event', 'feature.updated')->metadata, true);

    expect($created['after'])->toMatchArray(['key' => 'nova.funcao', 'name' => 'Nova']);
    expect($updated['before'])->toBe(['name' => 'Nova'])->and($updated['after'])->toBe(['name' => 'Nova função']);
});

it('CA-13 records the rule before and after when it is set and reset', function () {
    $token = $this->adminToken();
    $plan = PlanFactory::new()->create();
    $feature = FeatureFactory::new()->create(['key' => 'usuarios.max', 'kind' => 'limit']);

    $this->asToken($token, 'PUT', "plans/{$plan->id}/features/{$feature->id}", ['enabled' => true, 'limit' => 5, 'visible' => true]);
    $this->asToken($token, 'PUT', "plans/{$plan->id}/features/{$feature->id}", ['enabled' => true, 'limit' => 9, 'visible' => true]);
    $this->asToken($token, 'DELETE', "plans/{$plan->id}/features/{$feature->id}");

    $metadata = collect(auditEvents())->map(fn ($e) => [$e->event, json_decode($e->metadata, true)]);
    $sets = $metadata->where(0, 'plan_feature.set')->pluck(1)->values();

    expect($sets[0]['before'])->toBeNull()->and($sets[0]['after']['limit'])->toBe(5);
    expect($sets[1]['before']['limit'])->toBe(5)->and($sets[1]['after']['limit'])->toBe(9);
    expect($metadata->firstWhere(0, 'plan_feature.reset')[1]['before']['limit'])->toBe(9);
});
