<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Rotas do grupo `tenant`: exigem tenant resolvido e o aplicam no banco durante a requisição. */
class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly TenantContext $context,
        private readonly TenantDatabaseContext $database,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->resolver->resolve($request);

        if ($tenant === null) {
            return Problem::response(404, 'Not Found', 'Tenant não encontrado.');
        }

        $this->context->set($tenant);

        try {
            $this->database->apply($tenant->id);

            /** @var Response $response */
            $response = $next($request);

            return $response;
        } finally {
            // O contexto da aplicação sai primeiro: se a limpeza no banco falhar, ele não fica preso.
            $this->context->clear();
            $this->clearDatabase();
        }
    }

    /** Se não der para limpar o tenant da conexão, a conexão é descartada: nunca fica tenant residual. */
    private function clearDatabase(): void
    {
        try {
            $this->database->clear();
        } catch (Throwable) {
            DB::purge();
        }
    }
}
