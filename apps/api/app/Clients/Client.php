<?php

namespace App\Clients;

use App\Appointments\Appointment;
use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Notas são texto livre e podem ter dado de saúde: o histórico só registra que mudaram (LGPD).
     *
     * @return list<string>
     */
    public function maskedAuditFields(): array
    {
        return ['notes'];
    }

    /** @return HasMany<Appointment, $this> */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /** Traz a contagem (sem cancelados) e a média de avaliações junto da consulta. */
    /** @param  Builder<Client>  $query */
    public function scopeWithStats(Builder $query): void
    {
        $query->withCount(['appointments' => fn (Builder $q) => $q->where('status', '!=', 'canceled')])
            ->withAvg('appointments as average_review', 'client_review');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        $count = $this->getAttribute('appointments_count');
        $average = $this->getAttribute('average_review');

        if ($count === null) {
            $count = $this->appointments()->where('status', '!=', 'canceled')->count();
            $average = $this->appointments()->avg('client_review');
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'instagram' => $this->instagram,
            'notes' => $this->notes,
            'appointments_count' => (int) (is_numeric($count) ? $count : 0),
            'average_review' => is_numeric($average) ? round((float) $average, 2) : null,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
