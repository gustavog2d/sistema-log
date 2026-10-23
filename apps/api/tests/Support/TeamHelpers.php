<?php

use App\Mail\InvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** Chama uma rota da equipe como a pessoa do token. */
function team($test, string $token, string $method, string $slug, string $path, array $data = []): TestResponse
{
    return $test->withToken($token)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data);
}

/** Negócio com a dona e, opcionalmente, outras pessoas; devolve [token da dona, negócio]. */
function teamOf($test, string $slug = 'neg', array $others = []): array
{
    $owner = (fn () => $this->workspace($slug, "dona-{$slug}@exemplo.com"))->call($test);

    foreach ($others as $email => $role) {
        $extra = (fn () => $this->workspace($slug.'x'.substr(md5($email), 0, 6), $email, $role))->call($test);
        // O auxiliar cria um negócio por chamada: move só o vínculo recém-criado para o negócio principal.
        DB::connection('pgsql_admin')->table('memberships')->where('tenant_id', $extra->tenant_id)->where('user_id', $extra->user_id)->update(['tenant_id' => $owner->tenant_id]);
    }

    return [(fn () => $this->tokenFor($slug, "dona-{$slug}@exemplo.com"))->call($test), $owner];
}

function tokenOf($test, string $slug, string $email): string
{
    return (fn () => $this->tokenFor($slug, $email))->call($test);
}

function inviteToken(string $email): string
{
    $link = Mail::sent(InvitationMail::class, fn ($m) => $m->hasTo($email))->last()->link;
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    return (string) $query['token'];
}

function setLimit($test, int $limit): void
{
    $db = DB::connection('pgsql_admin');
    $feature = $db->table('features')->where('key', 'usuarios.max')->value('id');

    foreach ($db->table('plans')->pluck('id') as $plan) {
        $db->table('plan_features')->updateOrInsert(['plan_id' => $plan, 'feature_id' => $feature], [
            'id' => (string) Str::uuid(), 'enabled' => true, 'limit' => $limit, 'visible' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function membershipOf(string $email): object
{
    return DB::connection('pgsql_admin')->table('memberships')->join('users', 'users.id', '=', 'memberships.user_id')->where('users.email', $email)->select('memberships.*')->first();
}
