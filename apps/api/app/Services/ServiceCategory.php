<?php

namespace App\Services;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Categoria do catálogo de serviços (AGD-001).
 *
 * @property string $id
 * @property string $name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ServiceCategory extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'service_categories';

    protected $fillable = ['name'];

    public function auditedFields(): array
    {
        return ['name'];
    }

    /** @return HasMany<Service, $this> */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'category_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'services_count' => is_numeric($this->getAttribute('services_count')) ? (int) $this->getAttribute('services_count') : 0,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
