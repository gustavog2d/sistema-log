<?php

namespace App\Professionals;

use App\Audit\Auditor;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Regras de profissionais, expediente e folgas (AGD-002). Os controllers só traduzem o pedido. */
class ProfessionalDirectory
{
    /** Cores sugeridas para a agenda, em rodízio (contraste bom sobre branco). */
    private const PALETTE = ['#2563EB', '#DB2777', '#059669', '#D97706', '#7C3AED', '#0891B2', '#DC2626', '#4F46E5'];

    public function __construct(private readonly Auditor $auditor) {}

    /** @return CursorPaginator<int, Professional> */
    public function list(string $status, int $limit): CursorPaginator
    {
        return Professional::query()
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->orderBy('name_key')->orderBy('id')
            ->cursorPaginate($limit);
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): Professional
    {
        $data['color'] ??= self::PALETTE[Professional::query()->count() % count(self::PALETTE)];

        try {
            return Professional::create($data)->refresh();
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicate($e);
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(Professional $professional, array $data): Professional
    {
        try {
            $professional->fill($data)->save();
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicate($e);
        }

        return $professional;
    }

    /** @return list<array{weekday: int, start: string, end: string}> */
    public function hours(Professional $professional): array
    {
        $hours = [];

        foreach (DB::table('working_hours')->where('professional_id', $professional->id)->orderBy('weekday')->orderBy('start_minute')->get(['weekday', 'start_minute', 'end_minute']) as $row) {
            $hours[] = [
                'weekday' => is_numeric($row->weekday) ? (int) $row->weekday : 0,
                'start' => self::clock(is_numeric($row->start_minute) ? (int) $row->start_minute : 0),
                'end' => self::clock(is_numeric($row->end_minute) ? (int) $row->end_minute : 0),
            ];
        }

        return $hours;
    }

    /**
     * Substitui o expediente inteiro, num registro só do histórico.
     *
     * @param  list<array{weekday: int, start: int, end: int}>  $ranges  início e fim em minutos desde a meia-noite
     * @return list<array{weekday: int, start: string, end: string}>
     */
    public function replaceHours(Professional $professional, array $ranges): array
    {
        DB::transaction(function () use ($professional, $ranges): void {
            // Serializa trocas simultâneas do mesmo expediente.
            DB::table('professionals')->where('id', $professional->id)->lockForUpdate()->first();

            $tenantId = (string) app(TenantContext::class)->id();
            $before = $this->describe($this->hours($professional));
            DB::table('working_hours')->where('professional_id', $professional->id)->delete();

            foreach ($ranges as $range) {
                DB::table('working_hours')->insert([
                    'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'professional_id' => $professional->id,
                    'weekday' => $range['weekday'], 'start_minute' => $range['start'], 'end_minute' => $range['end'],
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $after = $this->describe($this->hours($professional));

            if ($before !== $after) {
                $this->auditor->record('working_hours', $professional->id, 'updated', ['ranges' => $before], ['ranges' => $after]);
            }
        });

        return $this->hours($professional);
    }

    /** @return CursorPaginator<int, TimeOff> */
    public function timeOffs(?string $professionalId, ?Carbon $from, ?Carbon $to, int $limit): CursorPaginator
    {
        return TimeOff::query()
            // Uma pessoa vê as folgas dela e as do negócio todo.
            ->when($professionalId !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('professional_id', $professionalId)->orWhereNull('professional_id')))
            ->when($from !== null, fn (Builder $query) => $query->where('ends_at', '>', $from))
            ->when($to !== null, fn (Builder $query) => $query->where('starts_at', '<', $to))
            ->orderBy('starts_at')->orderBy('id')
            ->cursorPaginate($limit);
    }

    /** @param  array<string, mixed>  $data */
    public function createTimeOff(array $data): TimeOff
    {
        return TimeOff::create($data + ['professional_id' => null])->refresh();
    }

    /** @param  array<string, mixed>  $data */
    public function updateTimeOff(TimeOff $off, array $data): TimeOff
    {
        $off->fill($data);

        // Datas e fim depois do início valem para o conjunto final (a validação sozinha só vê o que veio no pedido).
        if ($off->ends_at->lessThanOrEqualTo($off->starts_at) || $off->starts_at->diffInDays($off->ends_at, true) > 366) {
            throw ValidationException::withMessages(['ends_at' => 'O fim precisa ser depois do início, em até 366 dias.']);
        }

        $off->save();

        return $off;
    }

    /**
     * @param  list<array{weekday: int, start: string, end: string}>  $hours
     * @return list<string>
     */
    private function describe(array $hours): array
    {
        return array_map(fn (array $range): string => "{$range['weekday']} {$range['start']}-{$range['end']}", $hours);
    }

    public static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function duplicate(UniqueConstraintViolationException $e): ValidationException
    {
        return ValidationException::withMessages(
            str_contains($e->getMessage(), 'professionals_tenant_user_unique')
                ? ['user_id' => 'Esta pessoa já está ligada a outro profissional.']
                : ['name' => 'Já existe um profissional com este nome.'],
        );
    }
}
