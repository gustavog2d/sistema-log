<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regra de um plano para uma funcionalidade. Sem linha, vale o padrão da funcionalidade.
 *
 * @property string $id
 * @property string $plan_id
 * @property string $feature_id
 * @property bool $enabled
 * @property int|null $limit
 * @property bool $visible
 */
class PlanFeature extends Model
{
    use HasUuids;

    protected $fillable = ['plan_id', 'feature_id', 'enabled', 'limit', 'visible'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'visible' => 'boolean'];
    }

    /** @return BelongsTo<Feature, $this> */
    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
