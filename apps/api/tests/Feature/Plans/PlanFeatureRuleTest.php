<?php

use App\Models\PlanFeature;
use Database\Factories\FeatureFactory;
use Database\Factories\PlanFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->plan = PlanFactory::new()->create();
    $this->toggle = FeatureFactory::new()->create(['key' => 'orcamento', 'kind' => 'toggle']);
    $this->limit = FeatureFactory::new()->create(['key' => 'usuarios.max', 'kind' => 'limit']);
});

it('CA-07 sets a limited rule and reads it back through the entitlements', function () {
    $token = $this->adminToken();

    $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/{$this->limit->id}", ['enabled' => true, 'limit' => 5, 'visible' => true])
        ->assertOk()
        ->assertJson(['feature' => 'usuarios.max', 'enabled' => true, 'limit' => 5, 'source' => 'plan']);

    $this->asToken($token, 'GET', "plans/{$this->plan->id}/entitlements")
        ->assertOk()
        ->assertJsonFragment(['feature' => 'usuarios.max', 'limit' => 5])
        ->assertJsonFragment(['feature' => 'orcamento', 'source' => 'default']);
});

it('CA-08 and CA-09 store blocked rules as visible or hidden', function () {
    $token = $this->adminToken();

    $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/{$this->toggle->id}", ['enabled' => false, 'visible' => true])
        ->assertOk()->assertJson(['enabled' => false, 'visible' => true]);

    $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/{$this->toggle->id}", ['enabled' => false, 'visible' => false])
        ->assertOk()->assertJson(['enabled' => false, 'visible' => false]);
});

it('CA-10 refuses a limit on a toggle feature', function () {
    $this->asToken($this->adminToken(), 'PUT', "plans/{$this->plan->id}/features/{$this->toggle->id}", ['enabled' => true, 'limit' => 3, 'visible' => true])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('CA-14 validates the rule fields', function (array $payload) {
    $this->asToken($this->adminToken(), 'PUT', "plans/{$this->plan->id}/features/{$this->limit->id}", $payload)->assertStatus(422);
})->with([
    'sem enabled' => [['visible' => true]],
    'limite negativo' => [['enabled' => true, 'limit' => -1, 'visible' => true]],
    'limite decimal' => [['enabled' => true, 'limit' => 1.5, 'visible' => true]],
    'enabled não booleano' => [['enabled' => 'talvez', 'visible' => true]],
]);

it('goes back to the feature default when the rule is removed', function () {
    $token = $this->adminToken();
    $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/{$this->limit->id}", ['enabled' => true, 'limit' => 5, 'visible' => true])->assertOk();

    $this->asToken($token, 'DELETE', "plans/{$this->plan->id}/features/{$this->limit->id}")->assertNoContent();

    $this->asToken($token, 'GET', "plans/{$this->plan->id}/entitlements")->assertJsonFragment(['feature' => 'usuarios.max', 'limit' => null, 'source' => 'default']);
});

it('answers 404 for an unknown plan or feature', function () {
    $token = $this->adminToken();

    $this->asToken($token, 'PUT', 'plans/'.Str::uuid()."/features/{$this->limit->id}", ['enabled' => true, 'visible' => true])->assertNotFound();
    $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/".Str::uuid(), ['enabled' => true, 'visible' => true])->assertNotFound();
});

it('manages the feature catalog', function () {
    $token = $this->adminToken();

    $created = $this->asToken($token, 'POST', 'features', [
        'key' => 'agenda.publica', 'name' => 'Agenda pública', 'kind' => 'toggle',
        'default_enabled' => true, 'default_visible' => true,
    ])->assertCreated()->assertJson(['key' => 'agenda.publica', 'kind' => 'toggle']);

    $this->asToken($token, 'PATCH', 'features/'.$created->json('id'), ['name' => 'Agenda pública de agendamento'])
        ->assertOk()->assertJson(['name' => 'Agenda pública de agendamento']);

    $this->asToken($token, 'GET', 'features')->assertOk()->assertJsonCount(3, 'data');
});

it('CA-14 validates the feature fields', function (array $payload) {
    $this->asToken($this->adminToken(), 'POST', 'features', array_merge(
        ['key' => 'nova.funcao', 'name' => 'Nova', 'kind' => 'toggle', 'default_enabled' => true, 'default_visible' => true],
        $payload,
    ))->assertStatus(422);
})->with([
    'chave repetida' => [['key' => 'orcamento']],
    'chave inválida' => [['key' => 'Chave Inválida']],
    'tipo inválido' => [['kind' => 'misto']],
    'limite padrão em toggle' => [['default_limit' => 3]],
]);

it('CA-14 validates the feature fields on update', function (array $payload) {
    $feature = FeatureFactory::new()->create(['key' => 'qualquer.coisa', 'kind' => 'toggle']);

    $this->asToken($this->adminToken(), 'PATCH', "features/{$feature->id}", $payload)->assertStatus(422);
})->with([
    'limite padrão em toggle' => [['default_limit' => 3]],
    'chave não pode mudar' => [['key' => 'outra.chave']],
    'tipo inválido' => [['kind' => 'misto']],
    'limite acima do máximo' => [['kind' => 'limit', 'default_limit' => 1_000_001]],
]);

it('RN-07 refuses to turn a limited feature into a toggle while it keeps a default limit', function () {
    $feature = FeatureFactory::new()->create(['key' => 'usuarios.max2', 'kind' => 'limit', 'default_limit' => 10]);

    $this->asToken($this->adminToken(), 'PATCH', "features/{$feature->id}", ['kind' => 'toggle'])->assertStatus(422);

    $this->asToken($this->adminToken(), 'PATCH', "features/{$feature->id}", ['kind' => 'toggle', 'default_limit' => null])
        ->assertOk()->assertJson(['kind' => 'toggle', 'default_limit' => null]);
});

it('CA-14 refuses a rule limit above the maximum', function () {
    $this->asToken($this->adminToken(), 'PUT', "plans/{$this->plan->id}/features/{$this->limit->id}", ['enabled' => true, 'limit' => 1_000_001, 'visible' => true])
        ->assertStatus(422);
});

it('CA-13 audits the rule exactly as stored, with a null limit when none is sent', function () {
    $token = $this->adminToken();
    $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/{$this->limit->id}", ['enabled' => true, 'limit' => 5, 'visible' => true]);
    $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/{$this->limit->id}", ['enabled' => true, 'visible' => true]);

    $metadata = DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'plan_feature.set')->orderBy('id')->pluck('metadata')->map(fn ($m) => json_decode($m, true));

    expect($metadata[1]['before']['limit'])->toBe(5)->and($metadata[1]['after'])->toBe(['enabled' => true, 'limit' => null, 'visible' => true]);
});

it('RN-07 clears stored limits when a feature becomes a toggle', function () {
    $token = $this->adminToken();
    $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/{$this->limit->id}", ['enabled' => true, 'limit' => 5, 'visible' => true])->assertOk();

    $this->asToken($token, 'PATCH', "features/{$this->limit->id}", ['kind' => 'toggle'])->assertOk();
    $this->asToken($token, 'PATCH', "features/{$this->limit->id}", ['kind' => 'limit'])->assertOk();

    $this->asToken($token, 'GET', "plans/{$this->plan->id}/entitlements")->assertJsonFragment(['feature' => 'usuarios.max', 'limit' => null]);
});

it('does not fail when the same rule is set twice in a row', function () {
    $token = $this->adminToken();

    foreach (range(1, 3) as $_) {
        $this->asToken($token, 'PUT', "plans/{$this->plan->id}/features/{$this->limit->id}", ['enabled' => true, 'limit' => 2, 'visible' => true])->assertOk();
    }

    expect(PlanFeature::count())->toBe(1);
});
