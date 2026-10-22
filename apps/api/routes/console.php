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
