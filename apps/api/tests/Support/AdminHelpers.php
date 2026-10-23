<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Chamada da equipe da plataforma em `/platform/tenants…`. */
function adm($test, string $token, string $method, string $path, array $data = []): TestResponse
{
    return (fn () => $this->asToken($token, $method, $path, $data))->call($test);
}

/** Cria o negócio com assinatura e a dona (direto no banco). Devolve o negócio e o id da dona. */
function adminTenant($test, string $slug = 'neg', string $status = 'active', array $subscription = [], string $ownerEmail = 'dona@exemplo.com'): object
{
    (fn () => $this->seedBillingCatalog())->call($test);
    $sub = (fn () => $this->subscription(['status' => $status] + $subscription, 'pm_fake_ok_x', $slug))->call($test);
    $db = DB::connection('pgsql_admin');
    $userId = (string) Str::uuid();
    $db->table('users')->insert(['id' => $userId, 'name' => 'Dona '.$slug, 'email' => $ownerEmail, 'password' => Hash::make('uma-senha-forte-123'), 'created_at' => now(), 'updated_at' => now()]);
    $db->table('memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $sub->tenant_id, 'user_id' => $userId, 'role' => 'owner', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

    return (object) ['id' => $sub->tenant_id, 'subscription_id' => $sub->id, 'owner_id' => $userId, 'slug' => $slug, 'email' => $ownerEmail];
}

/** Chamada de assinante (rota do negócio) com o token dado. */
function asTenant($test, string $token, string $method, string $slug, string $path, array $data = []): TestResponse
{
    return $test->withToken($token)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data);
}
