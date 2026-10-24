<?php

namespace App\Services;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Serviço oferecido pelo negócio (AGD-001). `price_cents` vazio significa "sob orçamento".
 *
 * @property string $id
 * @property string $name
 * @property string|null $description
 * @property string|null $category_id
 * @property int $duration_minutes
 * @property int|null $price_cents
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property bool $requires_deposit
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Service extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'services';

    protected $fillable = ['name', 'description', 'category_id', 'duration_minutes', 'price_cents', 'buffer_before_minutes', 'buffer_after_minutes', 'requires_deposit', 'status'];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price_cents' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'requires_deposit' => 'boolean',
        ];
    }

    public function auditedFields(): array
    {
        return ['name', 'description', 'category_id', 'duration_minutes', 'price_cents', 'buffer_before_minutes', 'buffer_after_minutes', 'requires_deposit', 'status'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'duration_minutes' => $this->duration_minutes,
            'price_cents' => $this->price_cents,
            'buffer_before_minutes' => $this->buffer_before_minutes,
            'buffer_after_minutes' => $this->buffer_after_minutes,
            'requires_deposit' => $this->requires_deposit,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
