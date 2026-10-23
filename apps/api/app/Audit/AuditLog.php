<?php

namespace App\Audit;

use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Registro do histórico (FND-008). Só leitura para a API: quem grava é o {@see Auditor}.
 *
 * @property string $id
 * @property string $entity_type
 * @property string $entity_id
 * @property string $action
 * @property string|null $user_id
 * @property string|null $actor_name
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use BelongsToTenant;
    use HasUuids;

    public $timestamps = false;

    protected $table = 'audit_logs';

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'action' => $this->action,
            'actor' => $this->actor_name === null && $this->user_id === null ? null : ['id' => $this->user_id, 'name' => (string) $this->actor_name],
            'before' => $this->before,
            'after' => $this->after,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
