<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Limite de usuários por plano (chave do plano => limite; `null` sem limite). */
function planUserLimits($test, array $limits): void
{
    $db = DB::connection('pgsql_admin');
    $feature = $db->table('features')->where('key', 'usuarios.max')->value('id');

    foreach ($limits as $plan => $limit) {
        $db->table('plan_features')->updateOrInsert(['plan_id' => $db->table('plans')->where('key', $plan)->value('id'), 'feature_id' => $feature], [
            'id' => (string) Str::uuid(), 'enabled' => true, 'limit' => $limit, 'visible' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/** Dona de um negócio ativo no plano profissional, com o período de 30 dias a 10 dias do começo (travado no tempo). */
function planOwner($test, string $slug = 'neg', string $status = 'active', array $subscription = []): array
{
    Carbon::setTestNow('2026-11-10 12:00:00');

    return ownerOf($test, $slug, $status, $subscription + ($status === 'active'
        ? ['current_period_start' => Carbon::parse('2026-10-31 12:00:00'), 'current_period_end' => Carbon::parse('2026-11-30 12:00:00')]
        : []));
}

function changePlan($test, string $token, string $slug, string $plan, string $key = 'k1'): TestResponse
{
    return manage($test, $token, 'PUT', $slug, 'subscription/plan', ['plan' => $plan], $key);
}

function planKeyOf(object $workspace): string
{
    return (string) DB::connection('pgsql_admin')->table('plans')->where('id', subscriptionOf($workspace)->plan_id)->value('key');
}

/** Acrescenta pessoas ativas ao negócio (além da dona). */
function addPeople(object $workspace, int $count): void
{
    $db = DB::connection('pgsql_admin');

    for ($i = 0; $i < $count; $i++) {
        $user = (string) Str::uuid();
        $db->table('users')->insert(['id' => $user, 'name' => 'Pessoa '.$i, 'email' => "p{$i}-".Str::random(5).'@exemplo.com', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('memberships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $workspace->tenant_id, 'user_id' => $user, 'role' => 'professional', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
}

function auditCount(string $event): int
{
    return DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', $event)->count();
}
