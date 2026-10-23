<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tokens de desafio e de acesso expirados (PLT-001).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Chaves de idempotência antigas (o cadastro guarda a resposta por um dia).
Schedule::call(fn () => DB::connection('pgsql_platform')->table('idempotency_keys')->where('created_at', '<', now()->subDay())->delete())
    ->name('idempotency:prune')->daily();

// Cobra o fim dos testes e as renovações; suspende quem não pagou (PLT-004).
Schedule::command('billing:run')->everyFiveMinutes()->withoutOverlapping();

// Avisos agendados: fim do teste chegando (PLT-010).
Schedule::command('notifications:run')->everyFifteenMinutes()->withoutOverlapping();

// Registro de avisos enviados: só serve para não repetir; guardado por 90 dias.
Schedule::call(fn () => DB::connection('pgsql_platform')->table('notification_log')->where('created_at', '<', now()->subDays(90))->delete())
    ->name('notification-log:prune')->daily();
