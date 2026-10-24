<?php

namespace App\Http\Controllers;

use App\Finance\FinanceReport;
use App\Finance\MonthlyGoal;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Financeiro do negócio (FIN-003). Só traduz o pedido; as contas estão em `FinanceReport`. */
class FinanceController extends Controller
{
    public function __construct(private readonly FinanceReport $report, private readonly MonthlyGoal $goal) {}

    public function summary(Request $request): JsonResponse
    {
        /** @var array{from: string, to: string} $data */
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // 366 dias inclusive os dois extremos: um ano bissexto inteiro.
        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 365) {
            throw ValidationException::withMessages(['to' => 'O período pode ter no máximo 366 dias.']);
        }

        return response()->json($this->report->summary($data['from'], $data['to']));
    }

    public function monthly(Request $request): JsonResponse
    {
        /** @var array{to?: string} $data */
        $data = $request->validate(['to' => ['sometimes', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', $this->yearInRange()]]);
        $to = $data['to'] ?? Carbon::now('America/Sao_Paulo')->format('Y-m');

        return response()->json($this->report->monthly($to));
    }

    public function calendar(Request $request): JsonResponse
    {
        /** @var array{month: string} $data */
        $data = $request->validate(['month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/', $this->yearInRange()]]);

        return response()->json(['month' => $data['month'], 'days' => $this->report->calendar($data['month'])]);
    }

    public function goal(): JsonResponse
    {
        return response()->json(['monthly_goal_cents' => $this->goal->get()]);
    }

    public function setGoal(Request $request): JsonResponse
    {
        /** @var array{monthly_goal_cents: int|null} $data */
        $data = $request->validate(['monthly_goal_cents' => ['present', 'nullable', 'integer', 'between:1,1000000000']]);

        return response()->json(['monthly_goal_cents' => $this->goal->set($data['monthly_goal_cents'])]);
    }

    private function yearInRange(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $year = is_string($value) ? (int) substr($value, 0, 4) : 0;

            if ($year < 2000 || $year > 2100) {
                $fail('O ano precisa estar entre 2000 e 2100.');
            }
        };
    }
}
