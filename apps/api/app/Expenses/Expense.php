<?php

namespace App\Expenses;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Gasto do negócio em um dia (FIN-002). `spent_on` é a data no calendário do negócio, sem horário.
 *
 * @property string $id
 * @property string $category_id
 * @property string $description
 * @property int $amount_cents
 * @property string $spent_on
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Expense extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'expenses';

    protected $fillable = ['category_id', 'description', 'amount_cents', 'spent_on'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'spent_on' => 'date:Y-m-d'];
    }

    public function auditedFields(): array
    {
        return ['category_id', 'description', 'amount_cents', 'spent_on'];
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $category = $this->category;

        return [
            'id' => $this->id,
            'category' => ['id' => $this->category_id, 'name' => $category->name ?? ''],
            'description' => $this->description,
            'amount_cents' => $this->amount_cents,
            'spent_on' => Carbon::parse($this->spent_on)->format('Y-m-d'),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
