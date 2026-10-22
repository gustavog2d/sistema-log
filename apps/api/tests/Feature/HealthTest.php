<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('reports api, database and redis as healthy', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'checks' => ['database' => 'ok', 'redis' => 'ok'],
        ]);
});

it('returns a problem details response when the database is down', function () {
    DB::shouldReceive('select')->andThrow(new RuntimeException('connection refused'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'status' => 503,
            'checks' => ['database' => 'fail'],
        ])
        ->assertJsonStructure(['type', 'title', 'status', 'detail']);
});

it('returns a problem details response when redis is down', function () {
    Redis::shouldReceive('ping')->andThrow(new RuntimeException('connection refused'));

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['checks' => ['redis' => 'fail']]);
});
