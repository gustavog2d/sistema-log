<?php

use App\Models\PlatformUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Chama uma rota de clientes como a pessoa do token, no subdomínio do negócio. */
function clientsApi($test, string $token, string $method, string $slug, string $path = 'clients', array $data = []): TestResponse
{
    return $test->withToken($token)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data);
}

function clientPayload(array $override = []): array
{
    return $override + ['name' => 'Bia Lima', 'phone' => '+5511999998888'];
}

/** Cliente direto no banco (papel dono), para montar o cenário. */
function seedClient(object $workspace, array $override = []): object
{
    $row = $override + [
        'id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'name' => 'Cliente '.Str::random(4),
        'phone' => '+55119'.random_int(10000000, 99999999), 'created_at' => now(), 'updated_at' => now(),
    ];
    DB::connection('pgsql_admin')->table('clients')->insert($row);

    return (object) $row;
}

/** Token de um acesso de suporte (30 minutos) ao negócio, como o que a equipe da plataforma recebe. */
function supportAccessToken(object $workspace): string
{
    $staff = PlatformUser::factory()->create();
    $id = (string) Str::uuid();
    $secret = Str::random(40);
    DB::connection('pgsql_admin')->table('user_access_tokens')->insert([
        'id' => $id, 'tenant_id' => $workspace->tenant_id, 'user_id' => $workspace->user_id, 'kind' => 'access',
        'token_hash' => hash('sha256', $secret), 'expires_at' => now()->addMinutes(30), 'created_at' => now(), 'impersonated_by' => $staff->id,
    ]);

    return "{$id}|{$secret}";
}
