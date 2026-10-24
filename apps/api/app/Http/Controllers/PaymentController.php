<?php

namespace App\Http\Controllers;

use App\Appointments\Appointment;
use App\Payments\Payment;
use App\Payments\PaymentInput;
use App\Payments\PaymentLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pagamentos do atendimento (FIN-001). Só traduz o pedido; as regras estão em `PaymentLedger`. */
class PaymentController extends Controller
{
    public function __construct(private readonly PaymentLedger $ledger) {}

    public function index(string $id): JsonResponse
    {
        $appointment = Appointment::query()->findOrFail($id);

        return response()->json([
            'data' => $this->ledger->list($appointment)->map(fn (Payment $p): array => $p->toApi())->values()->all(),
            'summary' => $this->ledger->summary($appointment),
        ]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $appointment = Appointment::query()->findOrFail($id);

        return response()->json($this->ledger->record($appointment, $this->validated($request, PaymentInput::createRules()))->toApi(), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $payment = Payment::query()->findOrFail($id);

        return response()->json($this->ledger->update($payment, $this->validated($request, PaymentInput::patchRules()))->toApi());
    }

    public function void(string $id): JsonResponse
    {
        return response()->json($this->ledger->void(Payment::query()->findOrFail($id))->toApi());
    }

    /**
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        /** @var array<string, mixed> $raw */
        $raw = $request->only(array_keys($rules));

        /** @var array<string, mixed> $data */
        $data = validator(PaymentInput::normalize($raw), $rules)->validate();

        return $data;
    }
}
