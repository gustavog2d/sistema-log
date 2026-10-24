<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

function apptApi($test, string $token, string $method, string $slug, string $path = 'appointments', array $data = []): TestResponse
{
    return $test->withToken($token)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data);
}

/**
 * Negócio de teste com dona, cliente, profissional (expediente das 08:00 às 20:00, todos os dias) e serviço de 60 minutos
 * com 10 minutos de intervalo depois. O relógio é congelado na segunda-feira 2026-11-02, 09:00 em Brasília.
 *
 * @return object{ws: object, token: string, client: object, pro: object, service: object, slug: string}
 */
function apptWorld($test, string $slug = 'neg'): object
{
    Carbon::setTestNow('2026-11-02 12:00:00');
    [$ws, $token] = ownerOf($test, $slug);
    $client = seedClient($ws, ['name' => 'Bia Lima', 'phone' => '+5511999998888']);
    $pro = seedProfessional($ws, ['name' => 'Ana Tattoo']);
    seedHours($ws, $pro, array_map(fn (int $day) => [$day, 480, 1200], [0, 1, 2, 3, 4, 5, 6]));
    $service = seedService($ws, ['name' => 'Tatuagem pequena', 'duration_minutes' => 60, 'price_cents' => 15000, 'buffer_after_minutes' => 10]);

    return (object) compact('ws', 'token', 'client', 'pro', 'service', 'slug');
}

/** Corpo mínimo de um agendamento (terça-feira 2026-11-03, 10:00 em Brasília, serviço de 60 minutos). */
function apptPayload(object $world, array $override = []): array
{
    return $override + [
        'client_id' => $world->client->id,
        'professional_id' => $world->pro->id,
        'service_id' => $world->service->id,
        'starts_at' => '2026-11-03T10:00:00-03:00',
    ];
}

/** Agendamento direto no banco (papel dono). `starts` e `ends` em UTC. */
function seedAppointment(object $world, array $override = []): object
{
    $starts = Carbon::parse($override['starts_at'] ?? '2026-11-03 13:00:00', 'UTC');
    $ends = Carbon::parse($override['ends_at'] ?? $starts->copy()->addHour(), 'UTC');
    $before = $override['buffer_before_minutes'] ?? 0;
    $after = $override['buffer_after_minutes'] ?? 0;

    $row = $override + [
        'id' => (string) Str::uuid(), 'tenant_id' => $world->ws->tenant_id, 'client_id' => $world->client->id,
        'professional_id' => $world->pro->id, 'service_id' => null, 'status' => 'scheduled',
        'created_at' => now(), 'updated_at' => now(),
    ];
    $row['starts_at'] = $starts;
    $row['ends_at'] = $ends;
    $row['blocked_from'] = $starts->copy()->subMinutes($before);
    $row['blocked_until'] = $ends->copy()->addMinutes($after);
    DB::connection('pgsql_admin')->table('appointments')->insert($row);

    return (object) $row;
}

function apptCount(): int
{
    return DB::connection('pgsql_admin')->table('appointments')->count();
}

/** Equipe do negócio: dona, gerente, recepção e dois profissionais (Ana ligada a `ana@`, Bia a `bia@`). */
function apptTeam($test): object
{
    $w = apptWorld($test);
    $db = DB::connection('pgsql_admin');

    foreach (['gerente@exemplo.com' => 'manager', 'recep@exemplo.com' => 'reception', 'ana@exemplo.com' => 'professional', 'bia@exemplo.com' => 'professional'] as $email => $role) {
        $extra = (fn () => $this->workspace('neg'.substr(md5($email), 0, 5), $email, $role))->call($test);
        $db->table('memberships')->where('tenant_id', $extra->tenant_id)->where('user_id', $extra->user_id)->update(['tenant_id' => $w->ws->tenant_id]);
    }

    $w->tokens = collect(['manager' => 'gerente@exemplo.com', 'reception' => 'recep@exemplo.com', 'ana' => 'ana@exemplo.com', 'bia' => 'bia@exemplo.com'])
        ->map(fn (string $email) => tokenOf($test, 'neg', $email))->all();
    $w->tokens['owner'] = $w->token;
    $db->table('professionals')->where('id', $w->pro->id)->update(['user_id' => $db->table('users')->where('email', 'ana@exemplo.com')->value('id')]);
    $w->bia = seedProfessional($w->ws, ['name' => 'Bia', 'user_id' => $db->table('users')->where('email', 'bia@exemplo.com')->value('id')]);
    seedHours($w->ws, $w->bia, array_map(fn (int $day) => [$day, 480, 1200], [0, 1, 2, 3, 4, 5, 6]));
    $w->anaAppointment = seedAppointment($w, ['value_total_cents' => 15000, 'notes' => 'da Ana']);
    $w->biaAppointment = seedAppointment($w, ['professional_id' => $w->bia->id, 'value_total_cents' => 9000, 'starts_at' => '2026-11-03 17:00:00', 'ends_at' => '2026-11-03 18:00:00']);

    return $w;
}

function payApi($test, string $token, string $method, string $path, array $data = [], ?string $key = null, string $slug = 'neg'): TestResponse
{
    $headers = $method === 'POST' && str_contains($path, '/payments') ? ['Idempotency-Key' => $key ?? (string) Str::uuid()] : [];

    return $test->withToken($token)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data, $headers);
}

/** Agendamento com valor combinado (padrão R$ 150,00) para os testes de pagamento. */
function payAppointment(object $world, array $override = []): object
{
    return seedAppointment($world, $override + ['value_total_cents' => 15000]);
}

function seedPayment(object $world, object $appointment, array $override = []): object
{
    $row = $override + [
        'id' => (string) Str::uuid(), 'tenant_id' => $world->ws->tenant_id, 'appointment_id' => $appointment->id,
        'type' => 'deposit', 'method' => 'pix', 'amount_cents' => 5000, 'paid_at' => now(), 'status' => 'received',
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::connection('pgsql_admin')->table('payments')->insert($row);

    return (object) $row;
}

function payBody(array $override = []): array
{
    return $override + ['type' => 'deposit', 'method' => 'pix', 'amount_cents' => 5000];
}
