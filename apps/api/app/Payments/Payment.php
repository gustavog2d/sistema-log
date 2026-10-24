<?php

namespace App\Payments;

use App\Appointments\Appointment;
use App\Audit\Audited;
use App\Audit\AuditedEntity;
use App\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Dinheiro recebido de um agendamento (FIN-001). Nunca é apagado nem muda de valor: o estorno marca `voided`.
 *
 * @property string $id
 * @property string $appointment_id
 * @property string $type
 * @property string $method
 * @property int $amount_cents
 * @property Carbon $paid_at
 * @property string $status
 * @property Carbon|null $voided_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Payment extends Model implements AuditedEntity
{
    use Audited;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'payments';

    protected $fillable = ['appointment_id', 'type', 'method', 'amount_cents', 'paid_at', 'status', 'voided_at'];

    protected function casts(): array
    {
        return ['amount_cents' => 'integer', 'paid_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function auditedFields(): array
    {
        return ['appointment_id', 'type', 'method', 'amount_cents', 'paid_at', 'status', 'voided_at'];
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'appointment_id' => $this->appointment_id,
            'type' => $this->type,
            'method' => $this->method,
            'amount_cents' => $this->amount_cents,
            'paid_at' => $this->paid_at->utc()->toIso8601String(),
            'status' => $this->status,
            'voided_at' => $this->voided_at?->utc()->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
