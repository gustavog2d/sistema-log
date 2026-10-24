<?php

namespace App\Appointments;

use App\Professionals\Professional;
use App\Services\Service;
use App\Support\BusinessTime;
use App\Support\ResourceInUse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regras da agenda (AGD-003): conflito de horário, expediente e folgas, situações e o que cada papel pode fazer.
 * Os controllers só traduzem o pedido.
 */
class AppointmentBook
{
    /** Passagens de situação permitidas (RN-05). */
    private const TRANSITIONS = [
        'pending' => ['scheduled', 'canceled'],
        'scheduled' => ['completed', 'canceled', 'no_show', 'pending'],
        'canceled' => ['scheduled'],
        'no_show' => ['scheduled'],
        'completed' => [],
    ];

    /** O que ainda pode mudar em cada situação (RN-06); `null` = tudo. */
    private const EDITABLE = [
        'completed' => ['notes', 'value_total_cents', 'client_review'],
        'canceled' => ['notes', 'status'],
        'no_show' => ['notes', 'status'],
    ];

    /** @return Collection<int, Appointment> */
    public function list(Visibility $who, Carbon $from, Carbon $to, ?string $professionalId, ?string $clientId, ?string $status, int $limit): Collection
    {
        return Appointment::query()->with(['client', 'professional', 'service'])
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->when($who->isRestricted(), fn (Builder $query) => $query->where('professional_id', $who->professionalId ?? '00000000-0000-0000-0000-000000000000'))
            ->when($professionalId !== null, fn (Builder $query) => $query->where('professional_id', $professionalId))
            ->when($clientId !== null, fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($status !== null, fn (Builder $query) => $query->where('status', $status))
            ->orderBy('starts_at')->orderBy('id')
            ->limit($limit)->get();
    }

    public function find(Visibility $who, string $id): Appointment
    {
        return Appointment::query()->with(['client', 'professional', 'service'])
            ->when($who->isRestricted(), fn (Builder $query) => $query->where('professional_id', $who->professionalId ?? '00000000-0000-0000-0000-000000000000'))
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AppointmentConflict
     * @throws OutsideAvailability
     * @throws ValidationException
     */
    public function create(array $data, Visibility $who): Appointment
    {
        $professionalId = self::text($data['professional_id']);

        if (! $who->canUseProfessional($professionalId)) {
            abort(403);
        }

        $service = is_string($data['service_id'] ?? null) ? Service::query()->find($data['service_id']) : null;
        $starts = Carbon::parse(self::text($data['starts_at']), 'UTC');
        $ends = is_string($data['ends_at'] ?? null)
            ? Carbon::parse($data['ends_at'], 'UTC')
            : ($service !== null ? $starts->copy()->addMinutes($service->duration_minutes) : throw ValidationException::withMessages(['ends_at' => 'Informe o fim ou escolha um serviço com duração.']));

        $this->assertPeriod($starts, $ends);
        $this->assertSessions($data['session_number'] ?? null, $data['sessions_total'] ?? null);

        if (($data['client_review'] ?? null) !== null && ($data['status'] ?? 'scheduled') !== 'completed') {
            throw ValidationException::withMessages(['client_review' => 'A avaliação só vale em atendimento finalizado.']);
        }

        // Quem não vê valores nunca define valor: vale o preço do serviço.
        $value = $who->seesValues() ? ($data['value_total_cents'] ?? $service?->price_cents) : $service?->price_cents;
        $before = $service->buffer_before_minutes ?? 0;
        $after = $service->buffer_after_minutes ?? 0;

        $attributes = [
            'client_id' => $data['client_id'], 'professional_id' => $professionalId, 'service_id' => $service?->id,
            'description' => $data['description'] ?? null, 'starts_at' => $starts, 'ends_at' => $ends,
            'buffer_before_minutes' => $before, 'buffer_after_minutes' => $after,
            'blocked_from' => $starts->copy()->subMinutes($before), 'blocked_until' => $ends->copy()->addMinutes($after),
            'status' => $data['status'] ?? 'scheduled', 'type' => $data['type'] ?? 'appointment', 'origin' => 'panel',
            'session_number' => $data['session_number'] ?? null, 'sessions_total' => $data['sessions_total'] ?? null,
            'value_total_cents' => $value, 'notes' => $data['notes'] ?? null, 'client_review' => $data['client_review'] ?? null,
        ];

        $this->assertFree($professionalId, $starts, $ends, $attributes['blocked_from'], $attributes['blocked_until'], null, (bool) ($data['allow_outside_hours'] ?? false), self::text($attributes['status']));

        return $this->persist(fn (): Appointment => Appointment::create($attributes), $professionalId, $attributes['blocked_from'], $attributes['blocked_until'], null)->load(['client', 'professional', 'service']);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AppointmentConflict
     * @throws OutsideAvailability
     * @throws ValidationException
     */
    public function update(Appointment $appointment, array $data, Visibility $who): Appointment
    {
        $allowOutside = (bool) ($data['allow_outside_hours'] ?? false);
        unset($data['allow_outside_hours']);

        // Quem não vê valores não altera valores (o campo é ignorado).
        if (! $who->seesValues()) {
            unset($data['value_total_cents']);
        }

        foreach (array_keys($data) as $field) {
            if (isset(self::EDITABLE[$appointment->status]) && ! in_array($field, self::EDITABLE[$appointment->status], true)) {
                throw ValidationException::withMessages([$field => 'Este campo não pode ser alterado nesta situação do agendamento.']);
            }
        }

        if (isset($data['professional_id']) && ! $who->canUseProfessional(self::text($data['professional_id']))) {
            abort(403);
        }

        $status = $this->nextStatus($appointment, $data['status'] ?? null);
        $data['status'] = $status;

        if (array_key_exists('client_review', $data) && $data['client_review'] !== null && $status !== 'completed') {
            throw ValidationException::withMessages(['client_review' => 'A avaliação só vale em atendimento finalizado.']);
        }

        $serviceChanged = array_key_exists('service_id', $data) && $data['service_id'] !== $appointment->service_id;
        $service = $serviceChanged
            ? (is_string($data['service_id']) ? Service::query()->find($data['service_id']) : null)
            : ($appointment->service_id === null ? null : Service::query()->find($appointment->service_id));
        $starts = isset($data['starts_at']) ? Carbon::parse(self::text($data['starts_at']), 'UTC') : $appointment->starts_at->copy()->utc();
        $startsChanged = ! $starts->equalTo($appointment->starts_at);

        $ends = match (true) {
            is_string($data['ends_at'] ?? null) => Carbon::parse($data['ends_at'], 'UTC'),
            $serviceChanged && $service !== null => $starts->copy()->addMinutes($service->duration_minutes),
            $startsChanged => $starts->copy()->addSeconds((int) $appointment->starts_at->diffInSeconds($appointment->ends_at, true)),
            default => $appointment->ends_at->copy()->utc(),
        };

        $this->assertPeriod($starts, $ends);
        $this->assertSessions(array_key_exists('session_number', $data) ? $data['session_number'] : $appointment->session_number, array_key_exists('sessions_total', $data) ? $data['sessions_total'] : $appointment->sessions_total);

        $before = $serviceChanged ? ($service->buffer_before_minutes ?? 0) : $appointment->buffer_before_minutes;
        $after = $serviceChanged ? ($service->buffer_after_minutes ?? 0) : $appointment->buffer_after_minutes;
        $professionalId = self::text($data['professional_id'] ?? $appointment->professional_id);
        $blockedFrom = $starts->copy()->subMinutes($before);
        $blockedUntil = $ends->copy()->addMinutes($after);

        $scheduleChanged = $professionalId !== $appointment->professional_id || $startsChanged || ! $ends->equalTo($appointment->ends_at)
            || $before !== $appointment->buffer_before_minutes || $after !== $appointment->buffer_after_minutes;
        $reactivated = in_array($appointment->status, ['canceled', 'no_show'], true) && in_array($status, ['pending', 'scheduled'], true);

        if (in_array($status, ['pending', 'scheduled'], true) && ($scheduleChanged || $reactivated)) {
            $this->assertFree($professionalId, $starts, $ends, $blockedFrom, $blockedUntil, $appointment->id, $allowOutside, $status);
        }

        $changes = array_diff_key($data, ['starts_at' => 1, 'ends_at' => 1, 'allow_outside_hours' => 1]);
        $changes += ['starts_at' => $starts, 'ends_at' => $ends, 'buffer_before_minutes' => $before, 'buffer_after_minutes' => $after, 'blocked_from' => $blockedFrom, 'blocked_until' => $blockedUntil];

        return $this->persist(function () use ($appointment, $changes): Appointment {
            $appointment->fill($changes)->save();

            return $appointment;
        }, $professionalId, $blockedFrom, $blockedUntil, $appointment->id)->load(['client', 'professional', 'service']);
    }

    /** @throws ResourceInUse */
    public function delete(Appointment $appointment): void
    {
        if ($appointment->status === 'completed') {
            throw new ResourceInUse('appointment_completed', 'O atendimento finalizado não pode ser excluído. Use as situações para corrigir.');
        }

        $appointment->delete();
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function nextStatus(Appointment $appointment, mixed $requested): string
    {
        if (! is_string($requested) || $requested === $appointment->status) {
            return $appointment->status;
        }

        if (! in_array($requested, self::TRANSITIONS[$appointment->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => 'Não é possível passar deste agendamento para essa situação.']);
        }

        return $requested;
    }

    private function assertPeriod(Carbon $starts, Carbon $ends): void
    {
        if ($ends->lessThanOrEqualTo($starts)) {
            throw ValidationException::withMessages(['ends_at' => 'O fim precisa ser depois do início.']);
        }

        if ($starts->diffInMinutes($ends, true) > 24 * 60) {
            throw ValidationException::withMessages(['ends_at' => 'O agendamento pode ter no máximo 24 horas.']);
        }
    }

    private function assertSessions(mixed $number, mixed $total): void
    {
        if (is_int($number) && is_int($total) && $number > $total) {
            throw ValidationException::withMessages(['session_number' => 'A sessão não pode ser maior que o total de sessões.']);
        }
    }

    /**
     * Expediente e folgas (liberável com o encaixe) e conflito com outro agendamento (nunca liberável).
     *
     * @throws OutsideAvailability
     * @throws AppointmentConflict
     */
    private function assertFree(string $professionalId, Carbon $starts, Carbon $ends, Carbon $blockedFrom, Carbon $blockedUntil, ?string $ignoreId, bool $allowOutside, string $status): void
    {
        if (! $allowOutside) {
            $reasons = $this->outsideReasons($professionalId, $starts, $ends);

            if ($reasons !== []) {
                // O conflito vale mais que o encaixe: se há conflito, ele é o erro mostrado.
                $this->assertNoConflict($professionalId, $blockedFrom, $blockedUntil, $ignoreId);

                throw new OutsideAvailability($reasons);
            }
        }

        $this->assertNoConflict($professionalId, $blockedFrom, $blockedUntil, $ignoreId);
    }

    /** @return list<string> */
    private function outsideReasons(string $professionalId, Carbon $starts, Carbon $ends): array
    {
        $reasons = [];
        $localStart = $starts->copy()->setTimezone(BusinessTime::ZONE);
        $localEnd = $ends->copy()->setTimezone(BusinessTime::ZONE);
        $startMinute = $localStart->hour * 60 + $localStart->minute;
        // Meia-noite do dia seguinte conta como 24:00 do dia de início.
        $sameDay = $localStart->isSameDay($localEnd);
        $endMinute = $sameDay ? $localEnd->hour * 60 + $localEnd->minute : ($localEnd->copy()->startOfDay()->equalTo($localEnd) && $localStart->copy()->addDay()->isSameDay($localEnd) ? 1440 : null);

        $fits = $endMinute !== null && DB::table('working_hours')
            ->where('professional_id', $professionalId)->where('weekday', $localStart->dayOfWeek)
            ->where('start_minute', '<=', $startMinute)->where('end_minute', '>=', $endMinute)->exists();

        if (! $fits) {
            $reasons[] = 'outside_working_hours';
        }

        $onTimeOff = DB::table('time_offs')
            ->where(fn (QueryBuilder $query) => $query->where('professional_id', $professionalId)->orWhereNull('professional_id'))
            ->where('starts_at', '<', $ends)->where('ends_at', '>', $starts)->exists();

        if ($onTimeOff) {
            $reasons[] = 'time_off';
        }

        return $reasons;
    }

    private function assertNoConflict(string $professionalId, Carbon $from, Carbon $until, ?string $ignoreId): void
    {
        $other = $this->findConflict($professionalId, $from, $until, $ignoreId);

        if ($other !== null) {
            throw new AppointmentConflict($other);
        }
    }

    private function findConflict(string $professionalId, Carbon $from, Carbon $until, ?string $ignoreId): ?Appointment
    {
        return Appointment::query()->where('professional_id', $professionalId)->whereIn('status', Appointment::ACTIVE)
            ->where('blocked_from', '<', $until)->where('blocked_until', '>', $from)
            ->when($ignoreId !== null, fn (Builder $query) => $query->whereKeyNot($ignoreId))
            ->orderBy('starts_at')->first();
    }

    /**
     * Grava; se o banco recusar por sobreposição (outra pessoa agendou ao mesmo tempo), responde o mesmo 409 do conflito.
     *
     * @param  callable(): Appointment  $write
     */
    private function persist(callable $write, string $professionalId, Carbon $from, Carbon $until, ?string $ignoreId): Appointment
    {
        try {
            return $write();
        } catch (QueryException $e) {
            // 23P01 = restrição de exclusão do PostgreSQL (`appointments_no_overlap`).
            if ($e->getCode() === '23P01') {
                throw new AppointmentConflict($this->findConflict($professionalId, $from, $until, $ignoreId));
            }

            throw $e;
        }
    }

    /** Profissional da agenda para as telas (ativo ou não): o horário livre só se calcula para profissional existente. */
    public function professional(Visibility $who, string $id): Professional
    {
        if (! $who->canUseProfessional($id)) {
            abort(404);
        }

        return Professional::query()->findOrFail($id);
    }
}
