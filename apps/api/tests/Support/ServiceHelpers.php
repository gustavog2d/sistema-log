<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Chama uma rota de serviços ou categorias como a pessoa do token, no subdomínio do negócio. */
function servicesApi($test, string $token, string $method, string $slug, string $path = 'services', array $data = []): TestResponse
{
    return $test->withToken($token)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data);
}

function servicePayload(array $override = []): array
{
    return $override + ['name' => 'Tatuagem pequena', 'duration_minutes' => 90];
}

function seedCategory(object $workspace, string $name = 'Tatuagens'): object
{
    $row = ['id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()];
    DB::connection('pgsql_admin')->table('service_categories')->insert($row);

    return (object) $row;
}

function seedService(object $workspace, array $override = []): object
{
    $row = $override + [
        'id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'name' => 'Serviço '.Str::random(5),
        'duration_minutes' => 60, 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::connection('pgsql_admin')->table('services')->insert($row);

    return (object) $row;
}

/** Define o nicho do negócio (papel dono). */
function setNiche(object $workspace, ?string $niche): void
{
    DB::connection('pgsql_admin')->table('tenants')->where('id', $workspace->tenant_id)->update(['niche' => $niche]);
}
