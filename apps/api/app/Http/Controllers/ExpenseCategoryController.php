<?php

namespace App\Http\Controllers;

use App\Expenses\ExpenseCategory;
use App\Expenses\ExpenseInput;
use App\Expenses\ExpenseLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Categorias de despesa (FIN-002). */
class ExpenseCategoryController extends Controller
{
    public function __construct(private readonly ExpenseLedger $ledger) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->ledger->categories()->map(fn (ExpenseCategory $category): array => $category->toApi())->values()->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->ledger->createCategory($this->name($request))->toApi(), 201);
    }

    public function defaults(): JsonResponse
    {
        return response()->json($this->ledger->createDefaults());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $category = ExpenseCategory::query()->findOrFail($id);

        return response()->json($this->ledger->renameCategory($category, $this->name($request, $category->id))->toApi());
    }

    public function destroy(string $id): Response
    {
        $this->ledger->deleteCategory(ExpenseCategory::query()->findOrFail($id));

        return response()->noContent();
    }

    private function name(Request $request, ?string $ignoreId = null): string
    {
        /** @var array<string, mixed> $raw */
        $raw = $request->only(['name']);
        $data = validator(ExpenseInput::normalize($raw), ExpenseInput::categoryRules($ignoreId))->validate();

        return is_string($data['name'] ?? null) ? $data['name'] : '';
    }
}
