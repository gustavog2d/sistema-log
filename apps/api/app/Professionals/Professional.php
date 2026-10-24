<?php

namespace App\Professionals;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Quem tem agenda no negócio (AGD-002).
 *
 * @property string $id
 * @property string $name
 * @property string $color
 * @property int $commission_percent
 * @property string|null $user_id
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Professional extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'professionals';

    protected $fillable = ['name', 'color', 'commission_percent', 'user_id', 'status'];

    protected function casts(): array
    {
        return ['commission_percent' => 'integer'];
    }

    public function auditedFields(): array
    {
        return ['name', 'color', 'commission_percent', 'user_id', 'status'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'color' => $this->color,
            'commission_percent' => $this->commission_percent,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
