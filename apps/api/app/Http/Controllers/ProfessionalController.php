<?php

namespace App\Http\Controllers;

use App\Professionals\Professional;
use App\Professionals\ProfessionalDirectory;
use App\Professionals\ProfessionalInput;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Profissionais e expediente semanal (AGD-002). Só traduz o pedido; as regras estão em `ProfessionalDirectory`. */
class ProfessionalController extends Controller
{
    public function __construct(private readonly ProfessionalDirectory $directory) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:active,inactive,all'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);

        $page = $this->directory->list($request->string('status', 'active')->toString(), $request->integer('limit', 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (Professional $professional): array => $professional->toApi())->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->directory->create($this->validated($request, false))->toApi(), 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(Professional::query()->findOrFail($id)->toApi());
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $professional = Professional::query()->findOrFail($id);

        return response()->json($this->directory->update($professional, $this->validated($request, true, $professional->id))->toApi());
    }

    public function destroy(string $id): Response
    {
        Professional::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    public function hours(string $id): JsonResponse
    {
        return response()->json(['data' => $this->directory->hours(Professional::query()->findOrFail($id))]);
    }

    public function setHours(Request $request, string $id): JsonResponse
    {
        $professional = Professional::query()->findOrFail($id);
        $time = fn (bool $isEnd): array => ['required', 'string', 'regex:'.($isEnd ? '/^(([01][0-9]|2[0-3]):[0-5][0-9]|24:00)$/' : '/^([01][0-9]|2[0-3]):[0-5][0-9]$/'), fn (string $attribute, mixed $value, \Closure $fail) => is_string($value) && (int) substr($value, 3, 2) % 5 !== 0 ? $fail('Use minutos de 5 em 5.') : null];

        /** @var array{ranges: list<array{weekday: int, start: string, end: string}>} $data */
        $data = $request->validate([
            'ranges' => ['present', 'array', 'max:70'],
            'ranges.*.weekday' => ['required', 'integer', Rule::in([0, 1, 2, 3, 4, 5, 6])],
            'ranges.*.start' => $time(false),
            'ranges.*.end' => $time(true),
        ]);

        $ranges = [];

        foreach ($data['ranges'] as $index => $range) {
            $start = self::minutes($range['start']);
            $end = self::minutes($range['end']);

            if ($end <= $start) {
                throw ValidationException::withMessages(["ranges.{$index}.end" => 'O fim precisa ser depois do início.']);
            }

            $ranges[] = ['weekday' => (int) $range['weekday'], 'start' => $start, 'end' => $end];
        }

        $this->assertNoOverlap($ranges);

        return response()->json(['data' => $this->directory->replaceHours($professional, $ranges)]);
    }

    private static function minutes(string $clock): int
    {
        return (int) substr($clock, 0, 2) * 60 + (int) substr($clock, 3, 2);
    }

    /** @param  list<array{weekday: int, start: int, end: int}>  $ranges */
    private function assertNoOverlap(array $ranges): void
    {
        usort($ranges, fn (array $a, array $b): int => [$a['weekday'], $a['start']] <=> [$b['weekday'], $b['start']]);

        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i]['weekday'] === $ranges[$i - 1]['weekday'] && $ranges[$i]['start'] < $ranges[$i - 1]['end']) {
                throw ValidationException::withMessages(['ranges' => 'Há faixas de horário que se sobrepõem no mesmo dia.']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial, ?string $ignoreId = null): array
    {
        $rules = ProfessionalInput::professionalRules($partial, $ignoreId);

        /** @var array<string, mixed> $raw */
        $raw = $request->only(array_keys($rules));

        /** @var array<string, mixed> $data */
        $data = validator(ProfessionalInput::normalize($raw), $rules)->validate();

        return $data;
    }
}
