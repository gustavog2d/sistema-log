<?php

namespace App\Clients;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cliente final do negócio (CLI-001). As colunas `search_*` e `phone_digits` são geradas pelo banco.
 *
 * @property string $id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property string|null $instagram
 * @property string|null $notes
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Client extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'clients';

    protected $fillable = ['name', 'phone', 'email', 'instagram', 'notes'];

    public function auditedFields(): array
    {
        return ['name', 'phone', 'email', 'instagram', 'notes'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'instagram' => $this->instagram,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
