<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Chama uma rota de profissionais, expediente ou folgas como a pessoa do token, no subdomínio do negócio. */
function profApi($test, string $token, string $method, string $slug, string $path = 'professionals', array $data = []): TestResponse
{
    return $test->withToken($token)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data);
}

function profPayload(array $override = []): array
{
    return $override + ['name' => 'Ana Tattoo'];
}

function seedProfessional(object $workspace, array $override = []): object
{
    $row = $override + [
        'id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'name' => 'Profissional '.Str::random(5),
        'color' => '#2563EB', 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::connection('pgsql_admin')->table('professionals')->insert($row);

    return (object) $row;
}

/** @param  list<array{0: int, 1: int, 2: int}>  $ranges  [dia, início em minutos, fim em minutos] */
function seedHours(object $workspace, object $professional, array $ranges): void
{
    foreach ($ranges as [$weekday, $start, $end]) {
        DB::connection('pgsql_admin')->table('working_hours')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'professional_id' => $professional->id,
            'weekday' => $weekday, 'start_minute' => $start, 'end_minute' => $end, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function seedTimeOff(object $workspace, array $override = []): object
{
    $row = $override + [
        'id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'professional_id' => null,
        'starts_at' => '2026-12-24 00:00:00', 'ends_at' => '2026-12-26 00:00:00', 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::connection('pgsql_admin')->table('time_offs')->insert($row);

    return (object) $row;
}

function timeOffPayload(array $override = []): array
{
    return $override + ['starts_at' => '2026-12-24T00:00:00-03:00', 'ends_at' => '2026-12-26T00:00:00-03:00', 'reason' => 'Recesso'];
}
