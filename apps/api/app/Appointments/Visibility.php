<?php

namespace App\Appointments;

use App\Professionals\Professional;
use App\TenantAuth\AuthenticatedPerson;
use Illuminate\Http\Request;

/**
 * O que a pessoa pode ver e mexer na agenda (RN-07): dona e gerente tudo, recepção tudo mas sem valores,
 * profissional só o que é do profissional ligado a ela.
 */
final readonly class Visibility
{
    public function __construct(public string $role, public string $personId, public ?string $professionalId) {}

    public static function of(Request $request): self
    {
        $person = $request->attributes->get('tenant_person');

        if (! $person instanceof AuthenticatedPerson) {
            abort(401);
        }

        $own = $person->role === 'professional'
            ? Professional::query()->where('user_id', $person->id)->value('id')
            : null;

        return new self($person->role, $person->id, is_string($own) ? $own : null);
    }

    /** Valores (financeiro): dona, gerente e o profissional dono; a recepção não vê. */
    public function seesValues(): bool
    {
        return $this->role !== 'reception';
    }

    /** Comissão: dona, gerente e o profissional dono do agendamento. */
    public function seesCommission(Appointment $appointment): bool
    {
        return in_array($this->role, ['owner', 'manager'], true)
            || ($this->role === 'professional' && $this->professionalId === $appointment->professional_id);
    }

    /** Profissional enxerga e mexe só no próprio perfil. */
    public function isRestricted(): bool
    {
        return $this->role === 'professional';
    }

    public function canUseProfessional(string $professionalId): bool
    {
        return ! $this->isRestricted() || $this->professionalId === $professionalId;
    }
}
