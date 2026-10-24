<?php

namespace App\Appointments;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** O horário já está ocupado por outro agendamento ativo do mesmo profissional (contando os intervalos). */
class AppointmentConflict extends RuntimeException
{
    public function __construct(public readonly ?Appointment $other)
    {
        parent::__construct('Este horário já está ocupado por outro agendamento do profissional.');
    }

    public function render(): JsonResponse
    {
        $extra = ['code' => 'appointment_conflict'];

        if ($this->other !== null) {
            // Só o necessário para a pessoa achar o agendamento: nada de cliente, valor nem notas.
            $extra['conflict'] = ['id' => $this->other->id, 'starts_at' => $this->other->starts_at->utc()->toIso8601String(), 'ends_at' => $this->other->ends_at->utc()->toIso8601String()];
        }

        return Problem::response(409, 'Conflict', $this->getMessage(), $extra);
    }
}
