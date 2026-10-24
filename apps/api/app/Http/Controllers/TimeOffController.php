<?php

namespace App\Http\Controllers;

use App\Professionals\ProfessionalDirectory;
use App\Professionals\ProfessionalInput;
use App\Professionals\TimeOff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Folgas (AGD-002). */
class TimeOffController extends Controller
{
    public function __construct(private readonly ProfessionalDirectory $directory) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'professional_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'between:1,50'],
            'cursor' => ['sometimes', 'string', 'max:500'],
        ]);

        $page = $this->directory->timeOffs(
            $request->filled('professional_id') ? $request->string('professional_id')->toString() : null,
            $request->filled('from') ? Carbon::parse($request->string('from')->toString()) : null,
            $request->filled('to') ? Carbon::parse($request->string('to')->toString()) : null,
            $request->integer('limit', 20),
        );

        return response()->json([
            'data' => collect($page->items())->map(fn (TimeOff $off): array => $off->toApi())->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->directory->createTimeOff($this->validated($request, false))->toApi(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $off = TimeOff::query()->findOrFail($id);

        return response()->json($this->directory->updateTimeOff($off, $this->validated($request, true))->toApi());
    }

    public function destroy(string $id): Response
    {
        TimeOff::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial): array
    {
        $rules = ProfessionalInput::timeOffRules($partial);

        /** @var array<string, mixed> $raw */
        $raw = $request->only(array_keys($rules));

        /** @var array<string, mixed> $data */
        $data = validator(ProfessionalInput::normalize($raw), $rules)->validate();

        // Duração máxima (o banco também confere).
        if (is_string($data['starts_at'] ?? null) && is_string($data['ends_at'] ?? null) && Carbon::parse($data['starts_at'])->diffInDays(Carbon::parse($data['ends_at']), true) > 366) {
            throw ValidationException::withMessages(['ends_at' => 'A folga pode ter no máximo 366 dias.']);
        }

        return $data;
    }
}
