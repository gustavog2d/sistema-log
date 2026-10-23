<?php

use Illuminate\Support\Facades\DB;

it('CA-01 stores the lead with the origin and the consent, keeping only a hash of the IP', function () {
    postLead($this)->assertStatus(202);

    $lead = DB::connection('pgsql_admin')->table('leads')->first();

    expect($lead->email)->toBe('marina@exemplo.com')->and($lead->utm_source)->toBe('instagram')->and($lead->landing_path)->toBe('/')
        ->and($lead->status)->toBe('new')->and($lead->privacy_accepted_at)->not->toBeNull()->and($lead->privacy_version)->not->toBe('')
        ->and($lead->ip_hash)->toHaveLength(64)->and($lead->ip_hash)->not->toContain('127.0.0.1');
});

it('CA-01 accepts only the required fields', function () {
    $this->postJson('http://app.test/api/v1/leads', ['name' => 'Ana', 'email' => 'ana@exemplo.com', 'accept_privacy' => true, 'captcha_token' => 'captcha_ok'])->assertStatus(202);

    expect(leadCount())->toBe(1);
});

it('CA-02 refuses invalid data and stores nothing', function (array $override) {
    postLead($this, $override)->assertUnprocessable();

    expect(leadCount())->toBe(0);
})->with([
    'no consent' => [['accept_privacy' => false]],
    'consent missing' => [['accept_privacy' => null]],
    'bad email' => [['email' => 'não é e-mail']],
    'non ascii email' => [['email' => 'josé@exemplo.com']],
    'phone not E.164' => [['phone' => '11 99999-8888']],
    'unknown niche' => [['niche' => 'padaria']],
    'short name' => [['name' => 'A']],
    'long message' => [['message' => str_repeat('a', 501)]],
    'long utm' => [['utm_source' => str_repeat('a', 101)]],
]);

it('CA-04 answers 202 and stores nothing when the decoy field is filled', function () {
    postLead($this, ['company_site' => 'http://golpe.com'])->assertStatus(202);

    expect(leadCount())->toBe(0);
});

it('CA-05 does not repeat a lead within 24 hours and fills only what was empty', function () {
    postLead($this, ['message' => null, 'phone' => null])->assertStatus(202);
    postLead($this, ['email' => 'MARINA@exemplo.com', 'message' => 'Agora com mensagem', 'phone' => '+5511988887777'])->assertStatus(202);

    $lead = DB::connection('pgsql_admin')->table('leads')->first();
    expect(leadCount())->toBe(1)->and($lead->message)->toBe('Agora com mensagem')->and($lead->phone)->toBe('+5511988887777');
});

it('CA-05 keeps the existing data when the repeated request comes without them', function () {
    postLead($this)->assertStatus(202);
    postLead($this, ['message' => null, 'phone' => null])->assertStatus(202);

    expect(DB::connection('pgsql_admin')->table('leads')->value('message'))->toBe('Quero conhecer');
});

it('CA-05 creates a new lead after 24 hours', function () {
    postLead($this)->assertStatus(202);
    $this->travel(25)->hours();
    postLead($this)->assertStatus(202);

    expect(leadCount())->toBe(2);
});

it('gives the same answer for a new lead, a repeated one and a decoy so nothing leaks', function () {
    $new = postLead($this);
    $repeated = postLead($this);
    $decoy = postLead($this, ['company_site' => 'x']);

    expect([$new->status(), $repeated->status(), $decoy->status()])->toBe([202, 202, 202])
        ->and([$new->getContent(), $repeated->getContent(), $decoy->getContent()])->toBe(['', '', '']);
});

it('CA-06 limits the requests per hour from the same address', function () {
    $status = null;
    foreach (range(1, 7) as $i) {
        $status = postLead($this, ['email' => "pessoa{$i}@exemplo.com"])->status();
    }

    expect($status)->toBe(429)->and(leadCount())->toBe(5);
});

it('is not available on a business host', function () {
    $this->postJson('http://neg.app.test/api/v1/leads', leadPayload())->assertNotFound();
});

it('M2 refuses control characters and bad formats with 422 instead of failing on the database', function (array $override) {
    postLead($this, $override)->assertUnprocessable();
    expect(leadCount())->toBe(0);
})->with([
    'nul in name' => [['name' => "Ana\0Souza"]],
    'nul in message' => [['message' => "o\0i"]],
    'nul in utm' => [['utm_source' => "x\0y"]],
    'javascript referrer' => [['referrer' => 'javascript:alert(1)']],
    'landing path with tag' => [['landing_path' => '/<script>']],
    'landing path not a path' => [['landing_path' => 'http://golpe.com']],
    'quoted email' => [['email' => '"<img/src=x/onerror=alert(1)>"@a.com']],
]);

it('M1 does not overwrite the phone or the message of an existing lead', function () {
    postLead($this)->assertStatus(202);
    postLead($this, ['phone' => '+5511900000000', 'message' => 'mensagem de outra pessoa'])->assertStatus(202);

    $lead = DB::connection('pgsql_admin')->table('leads')->first();
    expect($lead->phone)->toBe('+5511999998888')->and($lead->message)->toBe('Quero conhecer');
});
