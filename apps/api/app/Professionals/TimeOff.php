<?php

namespace App\Professionals;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Folga de um profissional ou do negócio todo (`professional_id` vazio), em instantes (AGD-002).
 *
 * @property string $id
 * @property string|null $professional_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string|null $reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class TimeOff extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'time_offs';

    protected $fillable = ['professional_id', 'starts_at', 'ends_at', 'reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function auditedFields(): array
    {
        return ['professional_id', 'starts_at', 'ends_at', 'reason'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'professional_id' => $this->professional_id,
            'starts_at' => $this->starts_at->utc()->toIso8601String(),
            'ends_at' => $this->ends_at->utc()->toIso8601String(),
            'reason' => $this->reason,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
