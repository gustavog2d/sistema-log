<?php

namespace App\Services;

use App\Clients\ClientInput;
use Closure;

/** Normaliza e valida os campos de serviços e categorias (AGD-001). */
final class CatalogInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['name', 'description'] as $field) {
            if (array_key_exists($field, $input) && is_string($input[$field])) {
                // Espaços do começo, do fim e repetidos saem: "Corte  simples " vira "Corte simples".
                $input[$field] = trim((string) preg_replace('/[ \t]+/', ' ', $input[$field]));
            }
        }

        if (array_key_exists('description', $input) && $input['description'] === '') {
            $input['description'] = null;
        }

        return $input;
    }

    /** @return array<string, list<mixed>> */
    public static function serviceRules(bool $partial, ?string $ignoreId = null): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:100', ClientInput::plainText(), self::uniqueName(Service::class, $ignoreId)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500', ClientInput::noControl()],
            'category_id' => ['sometimes', 'nullable', 'bail', 'uuid', self::categoryExists()],
            'duration_minutes' => [$required, 'integer', 'between:5,720', 'multiple_of:5'],
            'price_cents' => ['sometimes', 'nullable', 'integer', 'between:0,100000000'],
            'buffer_before_minutes' => ['sometimes', 'integer', 'between:0,240', 'multiple_of:5'],
            'buffer_after_minutes' => ['sometimes', 'integer', 'between:0,240', 'multiple_of:5'],
            'requires_deposit' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,archived'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public static function categoryRules(?string $ignoreId = null): array
    {
        return ['name' => ['required', 'string', 'min:2', 'max:60', ClientInput::plainText(), self::uniqueName(ServiceCategory::class, $ignoreId)]];
    }

    /**
     * Nome único no negócio sem diferenciar maiúsculas e acentos (o escopo do negócio vem do modelo; o banco decide em uma corrida).
     *
     * @param  class-string<Service|ServiceCategory>  $model
     */
    private static function uniqueName(string $model, ?string $ignoreId): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($model, $ignoreId): void {
            if (! is_string($value) || $value === '') {
                return;
            }

            $query = $model::query()->whereRaw('name_key = lower(f_unaccent(?))', [$value]);

            if ($ignoreId !== null) {
                $query->whereKeyNot($ignoreId);
            }

            if ($query->exists()) {
                $fail('Já existe um registro com este nome.');
            }
        };
    }

    /** A categoria precisa ser do próprio negócio. */
    private static function categoryExists(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! ServiceCategory::query()->whereKey($value)->exists()) {
                $fail('A categoria não existe.');
            }
        };
    }
}
