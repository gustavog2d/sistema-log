<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** @return array<string, mixed> */
function leadPayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Marina Costa', 'email' => 'marina@exemplo.com', 'phone' => '+5511999998888', 'niche' => 'tatuagem', 'message' => 'Quero conhecer',
        'utm_source' => 'instagram', 'utm_medium' => 'social', 'utm_campaign' => 'lancamento', 'referrer' => 'https://instagram.com', 'landing_path' => '/',
        'accept_privacy' => true, 'captcha_token' => 'captcha_ok',
    ];
}

function postLead($test, array $overrides = []): TestResponse
{
    return $test->postJson('http://app.test/api/v1/leads', leadPayload($overrides));
}

/** Insere um lead direto no banco (papel dono). */
function makeLead(array $overrides = []): object
{
    $row = $overrides + [
        'id' => (string) Str::uuid(), 'name' => 'Lead '.Str::random(4), 'email' => Str::random(6).'@exemplo.com', 'status' => 'new', 'privacy_accepted_at' => now(), 'privacy_version' => '2026-10',
        'created_at' => now(), 'updated_at' => now(),
    ];
    DB::connection('pgsql_admin')->table('leads')->insert($row);

    return (object) $row;
}

function leadCount(): int
{
    return DB::connection('pgsql_admin')->table('leads')->count();
}
