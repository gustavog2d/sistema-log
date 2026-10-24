<?php

namespace App\Http\Controllers;

use App\Professionals\Professional;
use App\Professionals\ProfessionalDirectory;
use App\Professionals\ProfessionalInput;
use App\TenantAuth\AuthenticatedPerson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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
            'data' => collect($page->items())->map(fn (Professional $professional): array => $this->present($request, $professional))->values()->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->directory->create($this->validated($request, false))->toApi(), 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->present($request, Professional::query()->findOrFail($id)));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $professional = Professional::query()->findOrFail($id);

        return response()->json($this->directory->update($professional, $this->validated($request, true, $professional->id))->toApi());
    }

    public function destroy(string $id): Response
    {
        $this->directory->delete(Professional::query()->findOrFail($id));

        return response()->noContent();
    }

    public function hours(string $id): JsonResponse
    {
        return response()->json(['data' => $this->directory->hours(Professional::query()->findOrFail($id))]);
    }

    public function setHours(Request $request, string $id): JsonResponse
    {
        $professional = Professional::query()->findOrFail($id);

        /** @var array{ranges: list<array{weekday: int, start: string, end: string}>} $data */
        $data = $request->validate(ProfessionalInput::hoursRules());

        return response()->json(['data' => $this->directory->replaceHours($professional, ProfessionalInput::hoursToMinutes($data['ranges']))]);
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

    /**
     * A comissão é dado financeiro: dona e gerente veem a de todos; o profissional vê só a própria; a recepção não vê.
     *
     * @return array<string, mixed>
     */
    private function present(Request $request, Professional $professional): array
    {
        $data = $professional->toApi();
        $person = $request->attributes->get('tenant_person');
        $manager = $person instanceof AuthenticatedPerson && in_array($person->role, ['owner', 'manager'], true);
        $own = $person instanceof AuthenticatedPerson && $professional->user_id !== null && $professional->user_id === $person->id;

        if (! $manager && ! $own) {
            $data['commission_percent'] = null;
        }

        return $data;
    }
}
