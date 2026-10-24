<?php

namespace App\Http\Controllers;

use App\Appointments\Appointment;
use App\Appointments\AppointmentBook;
use App\Appointments\AppointmentInput;
use App\Appointments\Availability;
use App\Appointments\Visibility;
use App\Services\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Agenda (AGD-003). Só traduz o pedido; as regras estão em `AppointmentBook`. */
class AppointmentController extends Controller
{
    public function __construct(private readonly AppointmentBook $book, private readonly Availability $availability) {}

    public function index(Request $request): JsonResponse
    {
        $who = Visibility::of($request);
        /** @var array{from: string, to: string, professional_id?: string, client_id?: string, status?: string, limit?: int} $data */
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
            'professional_id' => ['sometimes', 'uuid'],
            'client_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', 'in:pending,scheduled,completed,canceled,no_show'],
            'limit' => ['sometimes', 'integer', 'between:1,500'],
        ]);

        $from = Carbon::parse((string) $data['from'])->utc();
        $to = Carbon::parse((string) $data['to'])->utc();

        if ($from->diffInDays($to, true) > 62) {
            throw ValidationException::withMessages(['to' => 'O período pode ter no máximo 62 dias.']);
        }

        $list = $this->book->list(
            $who, $from, $to,
            isset($data['professional_id']) ? (string) $data['professional_id'] : null,
            isset($data['client_id']) ? (string) $data['client_id'] : null,
            isset($data['status']) ? (string) $data['status'] : null,
            (int) ($data['limit'] ?? 200),
        );

        return response()->json(['data' => $list->map(fn (Appointment $a): array => $a->toApi($who->seesValues()))->values()->all()]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $who = Visibility::of($request);

        return response()->json($this->book->find($who, $id)->toApi($who->seesValues()));
    }

    public function store(Request $request): JsonResponse
    {
        $who = Visibility::of($request);
        $this->assertOwnProfessional($request, $who);

        return response()->json($this->book->create($this->validated($request, false), $who)->toApi($who->seesValues()), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $who = Visibility::of($request);
        $appointment = $this->book->find($who, $id);
        $this->assertOwnProfessional($request, $who);

        return response()->json($this->book->update($appointment, $this->validated($request, true, $appointment), $who)->toApi($who->seesValues()));
    }

    public function destroy(string $id): Response
    {
        $this->book->delete(Appointment::query()->findOrFail($id));

        return response()->noContent();
    }

    public function availability(Request $request, string $id): JsonResponse
    {
        $who = Visibility::of($request);
        $professional = $this->book->professional($who, $id);
        /** @var array{date: string, service_id?: string, duration_minutes?: int} $data */
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'service_id' => ['sometimes', 'uuid'],
            'duration_minutes' => ['required_without:service_id', 'integer', 'between:5,1440'],
        ]);

        $before = 0;
        $after = 0;
        $duration = (int) ($data['duration_minutes'] ?? 0);

        if (isset($data['service_id'])) {
            $service = Service::query()->find((string) $data['service_id']);

            if ($service === null) {
                throw ValidationException::withMessages(['service_id' => 'O serviço não existe.']);
            }

            $duration = $service->duration_minutes;
            $before = $service->buffer_before_minutes;
            $after = $service->buffer_after_minutes;
        }

        return response()->json(['data' => $this->availability->slots($professional, (string) $data['date'], $duration, $before, $after)]);
    }

    /** Quem só usa o próprio perfil recebe 403 antes de qualquer checagem de existência (não deixa descobrir ids). */
    private function assertOwnProfessional(Request $request, Visibility $who): void
    {
        $id = $request->input('professional_id');

        if (is_string($id) && ! $who->canUseProfessional($id)) {
            abort(403);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial, ?Appointment $current = null): array
    {
        $rules = AppointmentInput::rules($partial, $current);

        /** @var array<string, mixed> $raw */
        $raw = $request->only(array_keys($rules));

        /** @var array<string, mixed> $data */
        $data = validator(AppointmentInput::normalize($raw), $rules)->validate();

        return $data;
    }
}
