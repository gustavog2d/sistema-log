<?php

use App\Leads\LeadService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

it('CA-10 deletes discarded and old idle leads and keeps converted and recent ones', function () {
    makeLead(['email' => 'descartado@exemplo.com', 'status' => 'discarded']);
    makeLead(['email' => 'parado@exemplo.com', 'status' => 'new', 'updated_at' => now()->subMonths(13)]);
    makeLead(['email' => 'contatado-antigo@exemplo.com', 'status' => 'contacted', 'updated_at' => now()->subMonths(13)]);
    makeLead(['email' => 'convertido@exemplo.com', 'status' => 'converted', 'updated_at' => now()->subMonths(30)]);
    makeLead(['email' => 'recente@exemplo.com', 'status' => 'new', 'updated_at' => now()->subMonths(2)]);

    $deleted = app(LeadService::class)->prune();

    expect($deleted)->toBe(3)
        ->and(DB::connection('pgsql_admin')->table('leads')->orderBy('email')->pluck('email')->all())->toBe(['convertido@exemplo.com', 'recente@exemplo.com']);
});

it('CA-10 runs on a daily schedule', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description);

    expect($events)->toContain('leads:prune');
});
