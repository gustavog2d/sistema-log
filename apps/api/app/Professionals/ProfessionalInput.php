<?php

namespace App\Professionals;

use App\Clients\ClientInput;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            'starts_at' => [$required, 'date'],
            'ends_at' => [$required, 'date', 'after:starts_at'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:100', ClientInput::noControl()],
        ];
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
