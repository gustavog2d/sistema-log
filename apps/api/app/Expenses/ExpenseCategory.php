<?php

namespace App\Expenses;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Categoria de despesa do negócio (FIN-002).
 *
 * @property string $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ExpenseCategory extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'expense_categories';

    protected $fillable = ['name'];

    public function auditedFields(): array
    {
        return ['name'];
    }

    /** @return HasMany<Expense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'category_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $count = $this->getAttribute('expenses_count');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'expenses_count' => is_numeric($count) ? (int) $count : $this->expenses()->count(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
