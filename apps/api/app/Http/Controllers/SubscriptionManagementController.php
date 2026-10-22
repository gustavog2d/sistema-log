<?php

namespace App\Http\Controllers;

use App\Billing\SubscriptionManager;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Gestão da assinatura pela dona (PLT-007). Só traduz o pedido; as regras estão em `SubscriptionManager`. */
class SubscriptionManagementController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly SubscriptionManager $manager) {}

    public function paymentMethod(Request $request): JsonResponse
    {
        $request->validate(['payment_method_token' => ['required', 'string', 'max:200']]);
        $this->manager->changePaymentMethod($this->tenantId(), $request->string('payment_method_token')->toString());

        return $this->state();
    }

    public function cancel(): JsonResponse
    {
        $this->manager->cancel($this->tenantId());

        return $this->state();
    }

    public function reactivate(): JsonResponse
    {
        $this->manager->reactivate($this->tenantId());

        return $this->state();
    }

    public function invoices(Request $request): JsonResponse
    {
        $request->validate(['limit' => ['sometimes', 'integer', 'between:1,50'], 'cursor' => ['sometimes', 'string', 'max:500']]);
        $limit = $request->integer('limit', 20);

        // O RLS limita as faturas ao negócio em contexto.
        $page = DB::table('invoices')
            ->select('id', 'period_start', 'period_end', 'amount_cents', 'currency', 'status', 'paid_at')
            ->orderByDesc('period_start')->orderByDesc('id')
            ->cursorPaginate($limit);

        return response()->json([
            'data' => collect($page->items())->map(fn (mixed $row): array => (array) $row)->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    private function tenantId(): string
    {
        return (string) $this->context->id();
    }

    private function state(): JsonResponse
    {
        return response()->json(SubscriptionManager::summary(Subscription::query()->where('tenant_id', $this->tenantId())->firstOrFail()));
    }
}
