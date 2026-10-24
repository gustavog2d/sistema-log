<?php

namespace App\Appointments;

use App\Clients\Client;
use App\Clients\ClientInput;
use App\Professionals\Professional;
use App\Services\Service;
use Closure;
use Illuminate\Support\Carbon;

/** Normaliza e valida os campos de um agendamento (AGD-003). As regras do conjunto (horário, conflito, situação) ficam em `AppointmentBook`. */
final class AppointmentInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['description', 'notes'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                $input[$field] = trim($input[$field]) === '' ? null : trim($input[$field]);
            }
        }

        // Instantes entram sempre em UTC, sem segundos: o fuso do texto ("-03:00") não pode se perder na gravação.
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field]) && strtotime($input[$field]) !== false) {
                $input[$field] = Carbon::parse($input[$field])->utc()->format('Y-m-d H:i:s');
            }
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    public static function rules(bool $partial, ?Appointment $current = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'client_id' => [$required, 'bail', 'uuid', self::exists(Client::class, 'O cliente não existe.')],
            'professional_id' => [$required, 'bail', 'uuid', self::activeProfessional($current?->professional_id)],
            'service_id' => ['sometimes', 'nullable', 'bail', 'uuid', self::activeService($current?->service_id)],
            'starts_at' => [$required, 'date', 'after:2000-01-01', 'before:2100-01-01', self::fiveMinutes()],
            'ends_at' => ['sometimes', 'nullable', 'date', 'before:2100-01-01', self::fiveMinutes()],
            'description' => ['sometimes', 'nullable', 'string', 'max:255', ClientInput::noControl()],
            'status' => ['sometimes', 'in:pending,scheduled,completed,canceled,no_show'],
            'type' => ['sometimes', 'in:appointment,retouch'],
            'session_number' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'sessions_total' => ['sometimes', 'nullable', 'integer', 'between:1,99'],
            'value_total_cents' => ['sometimes', 'nullable', 'integer', 'between:0,100000000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000', ClientInput::noControl()],
            'client_review' => ['sometimes', 'nullable', 'integer', 'between:1,5'],
            'allow_outside_hours' => ['sometimes', 'boolean'],
        ];
    }

    /** @param  class-string<Client>  $model */
    private static function exists(string $model, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $message): void {
            if (is_string($value) && ! $model::query()->whereKey($value)->exists()) {
                $fail($message);
            }
        };
    }

    private static function activeProfessional(?string $unchanged): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($unchanged): void {
            if (! is_string($value) || $value === $unchanged) {
                return;
            }

            $professional = Professional::query()->find($value);

            if ($professional === null) {
                $fail('O profissional não existe.');
            } elseif ($professional->status !== 'active') {
                $fail('O profissional está inativo.');
            }
        };
    }

    private static function activeService(?string $unchanged): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($unchanged): void {
            if (! is_string($value) || $value === $unchanged) {
                return;
            }

            $service = Service::query()->find($value);

            if ($service === null) {
                $fail('O serviço não existe.');
            } elseif ($service->status !== 'active') {
                $fail('O serviço está arquivado.');
            }
        };
    }

    private static function fiveMinutes(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && strtotime($value) !== false && (Carbon::parse($value)->minute % 5 !== 0 || Carbon::parse($value)->second !== 0)) {
                $fail('Use horários de 5 em 5 minutos.');
            }
        };
    }
}
