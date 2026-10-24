<?php

namespace App\Appointments;

use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Clients\Client;
use App\Professionals\Professional;
use App\Services\Service;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Horário reservado para um cliente com um profissional (AGD-003).
 *
 * @property string $id
 * @property string $client_id
 * @property string $professional_id
 * @property string|null $service_id
 * @property string|null $description
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $buffer_before_minutes
 * @property int $buffer_after_minutes
 * @property Carbon $blocked_from
 * @property Carbon $blocked_until
 * @property string $status
 * @property string $type
 * @property string $origin
 * @property int|null $session_number
 * @property int|null $sessions_total
 * @property int|null $value_total_cents
 * @property string|null $notes
 * @property int|null $client_review
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Appointment extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    /** Situações que ocupam o horário do profissional. */
    public const ACTIVE = ['pending', 'scheduled', 'completed'];

    protected $table = 'appointments';

    protected $fillable = [
        'client_id', 'professional_id', 'service_id', 'description', 'starts_at', 'ends_at', 'buffer_before_minutes', 'buffer_after_minutes',
        'blocked_from', 'blocked_until', 'status', 'type', 'origin', 'session_number', 'sessions_total', 'value_total_cents', 'notes', 'client_review',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'blocked_from' => 'datetime', 'blocked_until' => 'datetime',
            'buffer_before_minutes' => 'integer', 'buffer_after_minutes' => 'integer', 'session_number' => 'integer', 'sessions_total' => 'integer',
            'value_total_cents' => 'integer', 'client_review' => 'integer',
        ];
    }

    public function auditedFields(): array
    {
        return ['client_id', 'professional_id', 'service_id', 'description', 'starts_at', 'ends_at', 'status', 'type', 'session_number', 'sessions_total', 'value_total_cents', 'notes', 'client_review'];
    }

    /**
     * As notas são texto livre e podem ter dado de saúde: o histórico só registra que existem ou mudaram (LGPD).
     *
     * @return list<string>
     */
    public function maskedAuditFields(): array
    {
        return ['notes', 'description'];
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /** @return BelongsTo<Professional, $this> */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /** @return BelongsTo<Service, $this> */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** @return array<string, mixed> */
    public function toApi(bool $seesValues): array
    {
        $client = $this->client;
        $professional = $this->professional;
        $service = $this->service;

        return [
            'id' => $this->id,
            'client' => ['id' => $this->client_id, 'name' => $client->name ?? ''],
            'professional' => ['id' => $this->professional_id, 'name' => $professional->name ?? '', 'color' => $professional->color ?? '#2563EB'],
            'service' => $service === null ? null : ['id' => $service->id, 'name' => $service->name],
            'starts_at' => $this->starts_at->utc()->toIso8601String(),
            'ends_at' => $this->ends_at->utc()->toIso8601String(),
            'description' => $this->description,
            'status' => $this->status,
            'type' => $this->type,
            'origin' => $this->origin,
            'session_number' => $this->session_number,
            'sessions_total' => $this->sessions_total,
            'value_total_cents' => $seesValues ? $this->value_total_cents : null,
            'notes' => $this->notes,
            'client_review' => $this->client_review,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
