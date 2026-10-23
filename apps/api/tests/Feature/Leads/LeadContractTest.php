<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

it('CA-13 returns bodies that respect the openapi contract', function () {
    $this->loadOpenApi('PLT-011-leads');
    postLead($this)->assertStatus(202);
    $team = $this->supportToken();

    $list = (fn () => $this->asToken($team, 'GET', 'leads'))->call($this)->assertOk();
    expect($this->schemaErrors($list->json(), 'LeadPage'))->toBe([]);

    $id = $list->json('data.0.id');
    $updated = (fn () => $this->asToken($team, 'PATCH', "leads/{$id}", ['status' => 'contacted']))->call($this)->assertOk();
    expect($this->schemaErrors($updated->json(), 'Lead'))->toBe([]);

    $invalid = postLead($this, ['captcha_token' => 'x'])->assertUnprocessable();
    expect($this->schemaErrors($invalid->json(), 'Problem'))->toBe([]);
});

it('CA-13 documents every route that exists', function () {
    $spec = Yaml::parseFile(base_path('../../docs/funcionalidades/PLT-011-leads/openapi.yaml'));
    $normalize = fn (string $uri) => preg_replace('/\{[^}]+\}/', '{x}', $uri);
    $declared = collect($spec['paths'])->keys()->map(fn ($path) => $normalize('api/v1'.$path))->sort()->values()->all();
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $normalize($route->uri()))
        ->filter(fn ($uri) => in_array($uri, ['api/v1/leads', 'api/v1/platform/leads', 'api/v1/platform/leads/{x}'], true))->unique()->sort()->values()->all();

    expect($routes)->toBe($declared);
});
