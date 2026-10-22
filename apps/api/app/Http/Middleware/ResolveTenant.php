<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantDatabaseContext;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
        $this->database->apply($tenant->id);

        try {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        } finally {
            $this->database->clear();
            $this->context->clear();
        }
    }
}
