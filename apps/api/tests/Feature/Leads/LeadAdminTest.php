<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function leads($test, string $token, string $method, string $path, array $data = [])
{
    return (fn () => $this->asToken($token, $method, $path, $data))->call($test);
}

it('CA-07 lists newest first with a cursor, filters and search', function () {
    foreach (range(1, 3) as $i) {
        makeLead(['name' => "Lead {$i}", 'email' => "lead{$i}@exemplo.com", 'created_at' => now()->subDays(4 - $i), 'utm_source' => $i === 2 ? 'google' : 'instagram', 'status' => $i === 3 ? 'contacted' : 'new']);
    }
    $team = $this->supportToken();

    $page = leads($this, $team, 'GET', 'leads?limit=2')->assertOk();
    expect(collect($page->json('data'))->pluck('name')->all())->toBe(['Lead 3', 'Lead 2'])->and($page->json('next_cursor'))->not->toBeNull();

    $next = leads($this, $team, 'GET', 'leads?limit=2&cursor='.$page->json('next_cursor'))->assertOk();
    expect(collect($next->json('data'))->pluck('name')->all())->toBe(['Lead 1'])->and($next->json('next_cursor'))->toBeNull();

    expect(collect(leads($this, $team, 'GET', 'leads?status=contacted')->json('data'))->pluck('name')->all())->toBe(['Lead 3'])
        ->and(collect(leads($this, $team, 'GET', 'leads?utm_source=google')->json('data'))->pluck('name')->all())->toBe(['Lead 2'])
        ->and(collect(leads($this, $team, 'GET', 'leads?q=lead1@')->json('data'))->pluck('name')->all())->toBe(['Lead 1'])
        ->and(leads($this, $team, 'GET', 'leads?q='.urlencode('%'))->json('data'))->toBe([]);
});

it('CA-07 refuses invalid limits, status and cursors', function (string $query) {
    leads($this, $this->supportToken(), 'GET', "leads?{$query}")->assertUnprocessable();
})->with(['limit=0', 'limit=51', 'status=banana', 'cursor=@@@', 'cursor='.'eyJmb28iOiJiYXIifQ']);

it('CA-12 never returns the IP hash', function () {
    makeLead(['ip_hash' => str_repeat('a', 64)]);

    $body = leads($this, $this->supportToken(), 'GET', 'leads')->assertOk()->getContent();

    expect($body)->not->toContain('ip_hash')->not->toContain(str_repeat('a', 64));
});

it('CA-08 changes status and notes', function () {
    $lead = makeLead();

    leads($this, $this->supportToken(), 'PATCH', "leads/{$lead->id}", ['status' => 'contacted', 'notes' => 'Liguei, vai pensar'])
        ->assertOk()->assertJsonPath('status', 'contacted')->assertJsonPath('notes', 'Liguei, vai pensar');

    expect(DB::connection('pgsql_admin')->table('leads')->value('status'))->toBe('contacted');
});

it('CA-08 refuses invalid values and answers 404 for an unknown lead', function () {
    $lead = makeLead();
    $team = $this->adminToken();

    leads($this, $team, 'PATCH', "leads/{$lead->id}", ['status' => 'banana'])->assertUnprocessable();
    leads($this, $team, 'PATCH', "leads/{$lead->id}", ['notes' => str_repeat('a', 1001)])->assertUnprocessable();
    leads($this, $team, 'PATCH', 'leads/'.Str::uuid(), ['status' => 'contacted'])->assertNotFound();
    expect(DB::connection('pgsql_admin')->table('leads')->value('status'))->toBe('new');
});

it('CA-09 asks for a login and is not reachable on a business host', function () {
    $this->getJson('http://app.test/api/v1/platform/leads')->assertUnauthorized();
    $this->getJson('http://neg.app.test/api/v1/platform/leads')->assertNotFound();
});

it('CA-11 audits the change with the status before and after and without the notes', function () {
    $lead = makeLead();

    leads($this, $this->supportToken(), 'PATCH', "leads/{$lead->id}", ['status' => 'converted', 'notes' => 'conversa particular sobre a pessoa'])->assertOk();

    $log = DB::connection('pgsql_admin')->table('platform_audit_logs')->where('event', 'lead.updated')->first();
    expect($log->metadata)->toContain('"status_before":"new"')->toContain('"status_after":"converted"')->not->toContain('conversa particular');
});

it('keeps the leads away from the API role', function () {
    expect(DB::connection('pgsql_admin')->selectOne("select has_table_privilege('app_runtime', 'leads', 'SELECT') as ok")->ok)->toBeFalse();
});
