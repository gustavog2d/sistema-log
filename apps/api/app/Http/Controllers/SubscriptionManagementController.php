<?php

namespace App\Http\Controllers;

use App\Billing\SubscriptionManager;
use App\Models\Subscription;
use App\Tenancy\TenantContext;
use App\TenantAuth\AuthenticatedPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Gestão da assinatura pela dona (PLT-007). Só traduz o pedido; as regras estão em `SubscriptionManager`. */
class SubscriptionManagementController extends Controller
{
    public function __construct(private readonly TenantContext $context, private readonly SubscriptionManager $manager) {}

    public function paymentMethod(Request $request): JsonResponse
    {
        $request->validate(['payment_method_token' => ['required', 'string', 'max:200']]);
        $this->manager->changePaymentMethod($this->tenantId(), $request->string('payment_method_token')->toString(), $this->actor($request));

        return $this->state();
    }

    public function cancel(Request $request): JsonResponse
    {
        $this->manager->cancel($this->tenantId(), $this->actor($request));

        return $this->state();
    }

    public function reactivate(Request $request): JsonResponse
    {
        $this->manager->reactivate($this->tenantId(), $this->actor($request));

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
            'data' => collect($page->items())->map(fn (mixed $row): array => $this->invoice(array_combine(array_map('strval', array_keys((array) $row)), array_values((array) $row))))->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function invoice(array $row): array
    {
        // As colunas não têm fuso (UTC): a API responde sempre em ISO 8601.
        foreach (['period_start', 'period_end', 'paid_at'] as $column) {
            $row[$column] = is_string($row[$column] ?? null) ? Carbon::parse($row[$column], 'UTC')->toIso8601String() : null;
        }

        return $row;
    }

    private function actor(Request $request): string
    {
        $person = $request->attributes->get('tenant_person');

        return $person instanceof AuthenticatedPerson ? $person->id : '';
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
