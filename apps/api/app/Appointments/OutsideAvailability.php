<?php

namespace App\Appointments;

use App\Http\Problem;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/** O agendamento não cabe no expediente ou cai em uma folga: quem agenda pode confirmar o encaixe. */
class OutsideAvailability extends RuntimeException
{
    /** @param  list<string>  $reasons  `outside_working_hours` e/ou `time_off` */
    public function __construct(public readonly array $reasons)
    {
        parent::__construct('O horário está fora do expediente ou em uma folga. Confirme o encaixe para agendar assim mesmo.');
    }

    public function render(): JsonResponse
    {
        return Problem::response(422, 'Unprocessable Entity', $this->getMessage(), ['code' => 'outside_availability', 'reasons' => $this->reasons]);
    }
}
