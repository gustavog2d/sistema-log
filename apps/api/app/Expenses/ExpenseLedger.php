<?php

namespace App\Expenses;

use App\Support\ResourceInUse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Regras de despesas e categorias (FIN-002). O controller só traduz o pedido. */
class ExpenseLedger
{
    /** Categorias que o botão "criar sugeridas" cadastra. */
    public const DEFAULT_CATEGORIES = ['Aluguel', 'Contas e serviços', 'Equipamentos', 'Impostos e taxas', 'Marketing', 'Materiais', 'Outros'];

    /** @return Collection<int, ExpenseCategory> */
    public function categories(): Collection
    {
        return ExpenseCategory::query()->withCount('expenses')->orderBy('name_key')->orderBy('id')->get();
    }

    public function createCategory(string $name): ExpenseCategory
    {
        try {
            return ExpenseCategory::create(['name' => $name])->loadCount('expenses');
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate();
        }
    }

    public function renameCategory(ExpenseCategory $category, string $name): ExpenseCategory
    {
        try {
            $category->fill(['name' => $name])->save();
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicate();
        }

        return $category->loadCount('expenses');
    }

    /** @throws ResourceInUse */
    public function deleteCategory(ExpenseCategory $category): void
    {
        $count = $category->expenses()->count();

        if ($count > 0) {
            throw new ResourceInUse('category_in_use', 'Esta categoria tem despesas. Mova ou apague as despesas antes.', ['count' => $count]);
        }

        $category->delete();
    }

    /** @return array{created: int, skipped: int} */
    public function createDefaults(): array
    {
        $created = 0;

        foreach (self::DEFAULT_CATEGORIES as $name) {
            if (ExpenseCategory::query()->whereRaw('name_key = lower(f_unaccent(?))', [$name])->exists()) {
                continue;
            }

            try {
                ExpenseCategory::create(['name' => $name]);
                $created++;
            } catch (UniqueConstraintViolationException) {
                // Outra chamada criou a mesma categoria agora: conta como já existente.
            }
        }

        return ['created' => $created, 'skipped' => count(self::DEFAULT_CATEGORIES) - $created];
    }

    /**
     * Despesas do período (datas inclusive) com os totais do filtro inteiro.
     *
     * @return array{data: Collection<int, Expense>, total_cents: int, count: int, by_category: list<array{category_id: string, name: string, total_cents: int}>}
     */
    public function list(string $from, string $to, ?string $categoryId, int $limit): array
    {
        $filter = fn () => Expense::query()->whereBetween('spent_on', [$from, $to])
            ->when($categoryId !== null, fn ($query) => $query->where('category_id', $categoryId));

        $data = $filter()->with('category')->orderByDesc('spent_on')->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get();
        $groups = $filter()->join('expense_categories as c', function (JoinClause $join): void {
            $join->on('c.id', '=', 'expenses.category_id')->on('c.tenant_id', '=', 'expenses.tenant_id');
        })->groupBy('expenses.category_id', 'c.name')
            ->select('expenses.category_id', 'c.name', DB::raw('SUM(expenses.amount_cents) as total_cents'), DB::raw('COUNT(*) as items'))
            ->orderByDesc('total_cents')->orderBy('c.name')->get();

        $byCategory = [];
        $total = 0;
        $count = 0;

        foreach ($groups as $group) {
            $sum = is_numeric($group->getAttribute('total_cents')) ? (int) $group->getAttribute('total_cents') : 0;
            $items = is_numeric($group->getAttribute('items')) ? (int) $group->getAttribute('items') : 0;
            $name = $group->getAttribute('name');
            $byCategory[] = ['category_id' => $group->category_id, 'name' => is_string($name) ? $name : '', 'total_cents' => $sum];
            $total += $sum;
            $count += $items;
        }

        return ['data' => $data, 'total_cents' => $total, 'count' => $count, 'by_category' => $byCategory];
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Expense
    {
        return Expense::create($data)->load('category');
    }

    /** @param  array<string, mixed>  $data */
    public function update(Expense $expense, array $data): Expense
    {
        $expense->fill($data)->save();

        return $expense->load('category');
    }

    private function duplicate(): ValidationException
    {
        return ValidationException::withMessages(['name' => 'Já existe uma categoria com este nome.']);
    }
}
