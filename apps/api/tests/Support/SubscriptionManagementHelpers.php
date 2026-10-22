<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** Chama uma rota de gestão da assinatura como a pessoa do token, no subdomínio do negócio. */
function manage($test, string $token, string $method, string $slug, string $path, array $data = [], ?string $key = null): TestResponse
{
    $headers = $key === null ? [] : ['Idempotency-Key' => $key];

    return $test->withToken($token)->withHeaders($headers)->json($method, "http://{$slug}.app.test/api/v1/{$path}", $data);
}

/** Cria o negócio, a dona e devolve [dados do negócio, token da dona]. */
function ownerOf($test, string $slug, string $status = 'active', array $subscription = []): array
{
    // Os auxiliares do teste são protegidos: chamados no contexto do próprio teste.
    $workspace = (fn () => $this->workspace($slug, "dona-{$slug}@exemplo.com", 'owner', $status))->call($test);

    if ($subscription !== []) {
        DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $workspace->tenant_id)->update($subscription);
    }

    return [$workspace, (fn () => $this->tokenFor($slug, "dona-{$slug}@exemplo.com"))->call($test)];
}

function subscriptionOf(object $workspace): object
{
    return DB::connection('pgsql_admin')->table('subscriptions')->where('tenant_id', $workspace->tenant_id)->first();
}
