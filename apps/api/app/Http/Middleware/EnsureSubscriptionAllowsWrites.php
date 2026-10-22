<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assinatura suspensa ou cancelada: somente leitura. A decisão lê o status atual a cada requisição
 * (sem cache), então suspender bloqueia na hora e regularizar libera na hora.
 */
class EnsureSubscriptionAllowsWrites
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $status = Subscription::query()->where('tenant_id', (string) $this->context->id())->value('status');

        if (in_array($status, ['trialing', 'active'], true)) {
            /** @var Response $response */
            $response = $next($request);

            return $response;
        }

        $code = $status === 'canceled' ? 'subscription_canceled' : 'subscription_suspended';

        return Problem::response(403, 'Forbidden', 'A assinatura não permite alterações: o acesso está somente leitura.', ['code' => $code]);
    }
}
