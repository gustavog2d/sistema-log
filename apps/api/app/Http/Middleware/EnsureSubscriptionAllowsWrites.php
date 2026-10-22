<?php

namespace App\Http\Middleware;

use App\Billing\SubscriptionAccess;
use App\Http\Problem;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assinatura suspensa ou cancelada: somente leitura. A decisão lê o status atual a cada requisição
 * (sem cache), então suspender bloqueia na hora e regularizar libera na hora. Vem depois de `tenant`.
 */
class EnsureSubscriptionAllowsWrites
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $this->pass($request, $next);
        }

        $tenantId = $this->context->id();

        if ($tenantId === null) {
            throw new LogicException('subscription.access precisa vir depois do middleware tenant.');
        }

        $status = Subscription::query()->where('tenant_id', $tenantId)->value('status');
        $code = SubscriptionAccess::blockedCode(is_string($status) ? $status : null);

        if ($code === null) {
            return $this->pass($request, $next);
        }

        return Problem::response(403, 'Forbidden', 'A assinatura não permite alterações: o acesso está somente leitura.', ['code' => $code]);
    }

    private function pass(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }
}
