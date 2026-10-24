<?php

namespace App\Appointments;

use App\Professionals\Professional;
use App\Support\BusinessTime;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Horários livres de um profissional em um dia (AGD-003, RN-09): expediente − folgas − agendamentos (com intervalos) − o passado. */
class Availability
{
    /** Passo entre os inícios oferecidos, em minutos. */
    private const STEP = 15;

    /** @return list<array{starts_at: string, ends_at: string}> */
    public function slots(Professional $professional, string $date, int $duration, int $before, int $after): array
    {
        $day = Carbon::parse($date, BusinessTime::ZONE)->startOfDay();
        $now = now();
        $ranges = DB::table('working_hours')->where('professional_id', $professional->id)->where('weekday', $day->dayOfWeek)
            ->orderBy('start_minute')->get(['start_minute', 'end_minute']);

        if ($ranges->isEmpty()) {
            return [];
        }

        $dayStart = $day->copy()->utc();
        $dayEnd = $day->copy()->addDay()->utc();
        $busy = Appointment::query()->where('professional_id', $professional->id)->whereIn('status', Appointment::ACTIVE)
            ->where('blocked_from', '<', $dayEnd->copy()->addHours(24))->where('blocked_until', '>', $dayStart->copy()->subHours(24))
            ->get(['blocked_from', 'blocked_until']);
        $offs = DB::table('time_offs')->where(fn (QueryBuilder $query) => $query->where('professional_id', $professional->id)->orWhereNull('professional_id'))
            ->where('starts_at', '<', $dayEnd)->where('ends_at', '>', $dayStart)->get(['starts_at', 'ends_at']);

        $slots = [];

        foreach ($ranges as $range) {
            $first = (int) ceil(((int) (is_numeric($range->start_minute) ? $range->start_minute : 0)) / self::STEP) * self::STEP;
            $last = (is_numeric($range->end_minute) ? (int) $range->end_minute : 0) - $duration;

            for ($minute = $first; $minute <= $last; $minute += self::STEP) {
                $starts = $day->copy()->addMinutes($minute)->utc();
                $ends = $starts->copy()->addMinutes($duration);

                if ($starts->lessThan($now)) {
                    continue;
                }

                $from = $starts->copy()->subMinutes($before);
                $until = $ends->copy()->addMinutes($after);

                if ($busy->contains(fn (Appointment $other): bool => $other->blocked_from->lt($until) && $other->blocked_until->gt($from))) {
                    continue;
                }

                if ($offs->contains(fn (object $off): bool => Carbon::parse(is_string($off->starts_at) ? $off->starts_at : '', 'UTC')->lt($ends) && Carbon::parse(is_string($off->ends_at) ? $off->ends_at : '', 'UTC')->gt($starts))) {
                    continue;
                }

                $slots[] = ['starts_at' => $starts->toIso8601String(), 'ends_at' => $ends->toIso8601String()];
            }
        }

        return $slots;
    }
}
