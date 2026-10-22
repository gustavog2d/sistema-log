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
