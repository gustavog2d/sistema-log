<?php

namespace App\Professionals;

use App\Clients\ClientInput;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Normaliza e valida profissionais, expediente e folgas (AGD-002). */
final class ProfessionalInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        if (array_key_exists('name', $input) && is_string($input['name'])) {
            $input['name'] = trim((string) preg_replace('/[ \t]+/', ' ', $input['name']));
        }

        if (array_key_exists('color', $input) && is_string($input['color'])) {
            $input['color'] = strtoupper(trim($input['color']));
        }

        // Instantes entram sempre em UTC: sem isso o fuso do texto ("-03:00") se perderia na gravação.
        foreach (['starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field]) && strtotime($input[$field]) !== false) {
                $input[$field] = Carbon::parse($input[$field])->utc()->format('Y-m-d H:i:s');
            }
        }

        if (array_key_exists('reason', $input) && is_string($input['reason'])) {
            $input['reason'] = trim($input['reason']) === '' ? null : trim($input['reason']);
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    public static function professionalRules(bool $partial, ?string $ignoreId = null): array
    {
        return [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'min:2', 'max:100', ClientInput::plainText(), self::uniqueName($ignoreId)],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9A-F]{6}$/'],
            'commission_percent' => ['sometimes', 'integer', 'between:0,100'],
            'user_id' => ['sometimes', 'nullable', 'bail', 'uuid', self::linkableUser($ignoreId)],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function timeOffRules(bool $partial): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'professional_id' => ['sometimes', 'nullable', 'bail', 'uuid', self::professionalExists()],
            // Datas razoáveis: evita estourar o limite do banco com anos absurdos.
            'starts_at' => [$required, 'date', 'after:2000-01-01', 'before:2100-01-01'],
            'ends_at' => [$required, 'date', 'after:starts_at', 'before:2100-01-01'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:100', ClientInput::noControl()],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function hoursRules(): array
    {
        $step = fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && (int) substr($value, 3, 2) % 5 !== 0 ? $fail('Use minutos de 5 em 5.') : null;

        return [
            'ranges' => ['present', 'array', 'max:70'],
            'ranges.*.weekday' => ['required', 'integer', 'between:0,6'],
            'ranges.*.start' => ['required', 'string', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $step],
            'ranges.*.end' => ['required', 'string', 'regex:/^(([01][0-9]|2[0-3]):[0-5][0-9]|24:00)$/', $step],
        ];
    }

    /**
     * Converte as faixas validadas ("09:00") em minutos e confere fim depois do início e a ausência de sobreposição no dia.
     *
     * @param  list<array{weekday: int, start: string, end: string}>  $ranges
     * @return list<array{weekday: int, start: int, end: int}>
     *
     * @throws ValidationException
     */
    public static function hoursToMinutes(array $ranges): array
    {
        $minutes = fn (string $clock): int => (int) substr($clock, 0, 2) * 60 + (int) substr($clock, 3, 2);
        $converted = [];

        foreach ($ranges as $index => $range) {
            $start = $minutes($range['start']);
            $end = $minutes($range['end']);

            if ($end <= $start) {
                throw ValidationException::withMessages(["ranges.{$index}.end" => 'O fim precisa ser depois do início.']);
            }

            $converted[] = ['weekday' => (int) $range['weekday'], 'start' => $start, 'end' => $end];
        }

        $sorted = $converted;
        usort($sorted, fn (array $a, array $b): int => [$a['weekday'], $a['start']] <=> [$b['weekday'], $b['start']]);

        for ($i = 1; $i < count($sorted); $i++) {
            if ($sorted[$i]['weekday'] === $sorted[$i - 1]['weekday'] && $sorted[$i]['start'] < $sorted[$i - 1]['end']) {
                throw ValidationException::withMessages(['ranges' => 'Há faixas de horário que se sobrepõem no mesmo dia.']);
            }
        }

        return $converted;
    }

    private static function uniqueName(?string $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $query = Professional::query()->whereRaw('name_key = lower(f_unaccent(?))', [$value]);

            if ($ignoreId !== null) {
                $query->whereKeyNot($ignoreId);
            }

            if ($query->exists()) {
                $fail('Já existe um profissional com este nome.');
            }
        };
    }

    /** A pessoa precisa ser da equipe ativa do negócio e não estar em outro profissional. */
    private static function linkableUser(?string $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignoreId): void {
            if (! is_string($value)) {
                return;
            }

            // Reenviar o mesmo vínculo (a pessoa pode ter saído da equipe depois) não é uma mudança: só vale conferir quando muda.
            if ($ignoreId !== null && Professional::query()->whereKey($ignoreId)->where('user_id', $value)->exists()) {
                return;
            }

            // O RLS limita os vínculos ao negócio em contexto.
            $member = DB::table('memberships')->where('user_id', $value)->where('status', 'active')->exists();

            if (! $member) {
                $fail('A pessoa não faz parte da equipe ativa do negócio.');

                return;
            }

            $query = Professional::query()->where('user_id', $value);

            if ($ignoreId !== null) {
                $query->whereKeyNot($ignoreId);
            }

            if ($query->exists()) {
                $fail('Esta pessoa já está ligada a outro profissional.');
            }
        };
    }

    private static function professionalExists(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! Professional::query()->whereKey($value)->exists()) {
                $fail('O profissional não existe.');
            }
        };
    }
}
