<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedBillingCatalog();
});

afterEach(fn () => Carbon::setTestNow());

/** Inícios livres em horário de Brasília (HH:MM), para ficar legível. */
function freeStarts($response): array
{
    return collect($response->assertOk()->json('data'))->map(fn ($slot) => Carbon::parse($slot['starts_at'])->setTimezone('America/Sao_Paulo')->format('H:i'))->all();
}

it('CA-09 offers starts every 15 minutes inside the working hours for the length of the service, plus its buffers', function () {
    $w = apptWorld($this);
    seedHours($w->ws, $w->pro, []);
    DB::connection('pgsql_admin')->table('working_hours')->where('professional_id', $w->pro->id)->delete();
    seedHours($w->ws, $w->pro, [[2, 540, 660]]);

    // 09:00-11:00, serviço de 60 min + 10 min de intervalo depois: o último início é às 10:00 (termina 11:00; o intervalo cabe fora do expediente).
    $starts = freeStarts(apptApi($this, $w->token, 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-11-03&service_id={$w->service->id}"));

    expect($starts)->toBe(['09:00', '09:15', '09:30', '09:45', '10:00']);
});

it('CA-09 returns the end of each slot and respects a duration given without a service', function () {
    $w = apptWorld($this);
    DB::connection('pgsql_admin')->table('working_hours')->where('professional_id', $w->pro->id)->delete();
    seedHours($w->ws, $w->pro, [[2, 540, 600]]);

    $slots = apptApi($this, $w->token, 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-11-03&duration_minutes=30")->assertOk()->json('data');

    expect($slots[0])->toBe(['starts_at' => '2026-11-03T12:00:00+00:00', 'ends_at' => '2026-11-03T12:30:00+00:00'])->and($slots)->toHaveCount(3);
});

it('CA-09 takes out what conflicts with appointments (counting buffers) and time offs', function () {
    $w = apptWorld($this);
    DB::connection('pgsql_admin')->table('working_hours')->where('professional_id', $w->pro->id)->delete();
    seedHours($w->ws, $w->pro, [[2, 540, 840]]);
    seedAppointment($w, ['starts_at' => '2026-11-03 13:00:00', 'ends_at' => '2026-11-03 14:00:00', 'buffer_after_minutes' => 10]);
    seedTimeOff($w->ws, ['professional_id' => null, 'starts_at' => '2026-11-03 16:00:00', 'ends_at' => '2026-11-03 16:30:00']);

    // Expediente 09:00-14:00 BRT. Agendado 10:00-11:00 (+10 min). Folga 13:00-13:30. Serviço novo: 60 min.
    $starts = freeStarts(apptApi($this, $w->token, 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-11-03&duration_minutes=60"));

    // Antes: só o início às 09:00 (termina às 10:00, quando o agendado começa). Depois: a partir de 11:10 (passo de 15 min: 11:15) até caber antes da folga das 13:00 (último início às 12:00).
    expect($starts)->toBe(['09:00', '11:15', '11:30', '11:45', '12:00']);
});

it('CA-09 offers nothing without working hours that day, for a past day and for a day too far in the past', function () {
    $w = apptWorld($this);
    DB::connection('pgsql_admin')->table('working_hours')->where('professional_id', $w->pro->id)->delete();
    seedHours($w->ws, $w->pro, [[2, 540, 1080]]);

    expect(freeStarts(apptApi($this, $w->token, 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-11-04&duration_minutes=60")))->toBe([])
        ->and(freeStarts(apptApi($this, $w->token, 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-10-27&duration_minutes=60")))->toBe([]);
});

it('CA-09 does not offer starts that already passed today', function () {
    $w = apptWorld($this);
    // Agora: segunda-feira 09:00 em Brasília.
    DB::connection('pgsql_admin')->table('working_hours')->where('professional_id', $w->pro->id)->delete();
    seedHours($w->ws, $w->pro, [[1, 480, 660]]);

    expect(freeStarts(apptApi($this, $w->token, 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-11-02&duration_minutes=60")))->toBe(['09:00', '09:15', '09:30', '09:45', '10:00']);
});

it('CA-09 refuses invalid parameters and a professional of another business', function () {
    $w = apptWorld($this);
    [$b] = ownerOf($this, 'negociob');
    $theirs = seedProfessional($b);
    $url = "professionals/{$w->pro->id}/availability";

    apptApi($this, $w->token, 'GET', 'neg', $url)->assertStatus(422)->assertJsonValidationErrors('date');
    apptApi($this, $w->token, 'GET', 'neg', "{$url}?date=03/11/2026&duration_minutes=60")->assertStatus(422)->assertJsonValidationErrors('date');
    apptApi($this, $w->token, 'GET', 'neg', "{$url}?date=2026-11-03")->assertStatus(422);
    apptApi($this, $w->token, 'GET', 'neg', "{$url}?date=2026-11-03&duration_minutes=3")->assertStatus(422)->assertJsonValidationErrors('duration_minutes');
    apptApi($this, $w->token, 'GET', 'neg', "{$url}?date=2026-11-03&service_id=x")->assertStatus(422)->assertJsonValidationErrors('service_id');
    apptApi($this, $w->token, 'GET', 'neg', "{$url}?date=2026-11-03&duration_minutes=60&service_id=0194a7c0-0000-7000-8000-000000000000")->assertStatus(422);
    apptApi($this, $w->token, 'GET', 'neg', "professionals/{$theirs->id}/availability?date=2026-11-03&duration_minutes=60")->assertNotFound();
});

it('CA-09 shows a professional only the own availability', function () {
    $w = apptTeam($this);

    apptApi($this, $w->tokens['ana'], 'GET', 'neg', "professionals/{$w->pro->id}/availability?date=2026-11-03&duration_minutes=60")->assertOk();
    apptApi($this, $w->tokens['ana'], 'GET', 'neg', "professionals/{$w->bia->id}/availability?date=2026-11-03&duration_minutes=60")->assertNotFound();
    apptApi($this, $w->tokens['reception'], 'GET', 'neg', "professionals/{$w->bia->id}/availability?date=2026-11-03&duration_minutes=60")->assertOk();
});
