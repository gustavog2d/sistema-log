<?php

namespace App\Http\Controllers;

use App\Expenses\Expense;
use App\Expenses\ExpenseInput;
use App\Expenses\ExpenseLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Despesas (FIN-002). Só traduz o pedido; as regras estão em `ExpenseLedger`. */
class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(Request $request): JsonResponse
    {
        /** @var array{from: string, to: string, category_id?: string, limit?: int} $data */
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'category_id' => ['sometimes', 'uuid'],
            'limit' => ['sometimes', 'integer', 'between:1,500'],
        ]);

        // 366 dias inclusive os dois extremos: um ano bissexto inteiro.
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'O período pode ter no máximo 366 dias.']);
        }

        $result = $this->ledger->list($data['from'], $data['to'], $data['category_id'] ?? null, (int) ($data['limit'] ?? 200));

        return response()->json([
            'data' => $result['data']->map(fn (Expense $expense): array => $expense->toApi())->values()->all(),
            'total_cents' => $result['total_cents'],
            'count' => $result['count'],
            'by_category' => $result['by_category'],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->ledger->create($this->validated($request, false))->toApi(), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Expense::query()->with('category')->findOrFail($id)->toApi());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $expense = Expense::query()->findOrFail($id);

        return response()->json($this->ledger->update($expense, $this->validated($request, true))->toApi());
    }

    public function destroy(string $id): Response
    {
        Expense::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial): array
    {
        $rules = ExpenseInput::expenseRules($partial);

        /** @var array<string, mixed> $raw */
        $raw = $request->only(array_keys($rules));

        /** @var array<string, mixed> $data */
        $data = validator(ExpenseInput::normalize($raw), $rules)->validate();

        return $data;
    }
}
