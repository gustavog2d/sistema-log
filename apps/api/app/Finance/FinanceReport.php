<?php

namespace App\Finance;

use App\Appointments\Appointment;
use App\Support\BusinessTime;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Números do financeiro (FIN-003), calculados na hora a partir de pagamentos, agendamentos e despesas.
 * Os dias são do calendário do negócio: o instante vira data no fuso de Brasília antes de comparar. O RLS limita ao negócio.
 */
class FinanceReport
{
    private const MONTH_PAID = "to_char((p.paid_at AT TIME ZONE ?)::date, 'YYYY-MM')";

    private const MONTH_STARTED = "to_char((a.starts_at AT TIME ZONE ?)::date, 'YYYY-MM')";

    private const DAY_PAID = "to_char((p.paid_at AT TIME ZONE ?)::date, 'YYYY-MM-DD')";

    private const DAY_STARTED = "to_char((a.starts_at AT TIME ZONE ?)::date, 'YYYY-MM-DD')";

    /** Colunas da previsão: valor, comissão (valor × percentual), quantidade e quanto falta receber (nunca negativo por agendamento). */
    private const EXPECTED_COLUMNS = "COALESCE(SUM(COALESCE(a.value_total_cents, 0)), 0) as expected, COALESCE(SUM(ROUND(COALESCE(a.value_total_cents, 0) * a.commission_percent / 100.0)), 0) as commission, COUNT(*) as items, COALESCE(SUM(GREATEST(COALESCE(a.value_total_cents, 0) - (SELECT COALESCE(SUM(p.amount_cents), 0) FROM payments p WHERE p.appointment_id = a.id AND p.tenant_id = a.tenant_id AND p.status = 'received'), 0)), 0) as to_receive";

    /**
     * @return array{received_cents: int, expected_cents: int, to_receive_cents: int, commission_received_cents: int, net_received_cents: int, commission_expected_cents: int, net_expected_cents: int, expenses_cents: int, result_cents: int, appointments_count: int, by_method: list<array{method: string, total_cents: int}>}
     */
    public function summary(string $from, string $to): array
    {
        $paid = $this->receivedQuery($from, $to)
            ->selectRaw('COALESCE(SUM(p.amount_cents), 0) as total, COALESCE(SUM(ROUND(p.amount_cents * a.commission_percent / 100.0)), 0) as commission')->first();
        $planned = $this->expectedQuery($from, $to)
            ->selectRaw(self::EXPECTED_COLUMNS)->first();
        $expenses = $this->int(DB::table('expenses')->whereBetween('spent_on', [$from, $to])->sum('amount_cents'));

        $received = $this->int($paid->total ?? 0);
        $commissionReceived = $this->cents($paid->commission ?? 0);
        $expected = $this->int($planned->expected ?? 0);
        $commissionExpected = $this->cents($planned->commission ?? 0);
        $netReceived = $received - $commissionReceived;

        $methods = $this->receivedQuery($from, $to)->groupBy('p.method')
            ->selectRaw('p.method as method, SUM(p.amount_cents) as total')->orderByRaw('SUM(p.amount_cents) desc')->orderBy('p.method')->get();

        return [
            'received_cents' => $received,
            'expected_cents' => $expected,
            'to_receive_cents' => $this->int($planned->to_receive ?? 0),
            'commission_received_cents' => $commissionReceived,
            'net_received_cents' => $netReceived,
            'commission_expected_cents' => $commissionExpected,
            'net_expected_cents' => $expected - $commissionExpected,
            'expenses_cents' => $expenses,
            'result_cents' => $netReceived - $expenses,
            'appointments_count' => $this->int($planned->items ?? 0),
            'by_method' => array_values($methods->map(fn (object $row): array => ['method' => is_string($row->method) ? $row->method : '', 'total_cents' => $this->int($row->total ?? 0)])->all()),
        ];
    }

    /**
     * Os 12 meses que terminam em `$to` (aaaa-mm), do mais antigo ao mais novo.
     *
     * @return array{months: list<array{month: string, received_cents: int, net_received_cents: int, expected_cents: int, expenses_cents: int, result_cents: int}>, totals: array{received_cents: int, net_received_cents: int, expected_cents: int, expenses_cents: int, result_cents: int}}
     */
    public function monthly(string $to): array
    {
        $last = Carbon::createFromFormat('!Y-m', $to, 'UTC') ?? Carbon::now('UTC')->startOfMonth();
        $first = $last->copy()->subMonths(11);
        $from = $first->format('Y-m-d');
        $until = $last->copy()->endOfMonth()->format('Y-m-d');

        $received = $this->receivedQuery($from, $until)->groupByRaw('1')
            ->selectRaw(self::MONTH_PAID.' as m, SUM(p.amount_cents) as total, SUM(ROUND(p.amount_cents * a.commission_percent / 100.0)) as commission', [BusinessTime::ZONE])->get()->keyBy('m');
        $expected = $this->expectedQuery($from, $until)->groupByRaw('1')
            ->selectRaw(self::MONTH_STARTED.' as m, SUM(COALESCE(a.value_total_cents, 0)) as expected', [BusinessTime::ZONE])->get()->keyBy('m');
        $expenses = DB::table('expenses')->whereBetween('spent_on', [$from, $until])->groupByRaw("to_char(spent_on, 'YYYY-MM')")
            ->selectRaw("to_char(spent_on, 'YYYY-MM') as m, SUM(amount_cents) as total")->get()->keyBy('m');

        $months = [];
        $totals = ['received_cents' => 0, 'net_received_cents' => 0, 'expected_cents' => 0, 'expenses_cents' => 0, 'result_cents' => 0];

        for ($i = 0; $i < 12; $i++) {
            $key = $first->copy()->addMonths($i)->format('Y-m');
            $in = $this->int($received[$key]->total ?? 0);
            $net = $in - $this->cents($received[$key]->commission ?? 0);
            $out = $this->int($expenses[$key]->total ?? 0);
            $row = ['month' => $key, 'received_cents' => $in, 'net_received_cents' => $net, 'expected_cents' => $this->int($expected[$key]->expected ?? 0), 'expenses_cents' => $out, 'result_cents' => $net - $out];
            $months[] = $row;

            foreach (array_keys($totals) as $field) {
                $totals[$field] += $row[$field];
            }
        }

        return ['months' => $months, 'totals' => $totals];
    }

    /**
     * Todos os dias do mês (aaaa-mm) com o movimento de cada dia.
     *
     * @return list<array{date: string, received_cents: int, expected_cents: int, appointments_count: int, expenses_cents: int}>
     */
    public function calendar(string $month): array
    {
        $start = Carbon::createFromFormat('!Y-m', $month, 'UTC') ?? Carbon::now('UTC')->startOfMonth();
        $from = $start->format('Y-m-d');
        $to = $start->copy()->endOfMonth()->format('Y-m-d');

        $received = $this->receivedQuery($from, $to)->groupByRaw('1')
            ->selectRaw(self::DAY_PAID.' as d, SUM(p.amount_cents) as total', [BusinessTime::ZONE])->get()->keyBy('d');
        $expected = $this->expectedQuery($from, $to)->groupByRaw('1')
            ->selectRaw(self::DAY_STARTED.' as d, SUM(COALESCE(a.value_total_cents, 0)) as expected, COUNT(*) as items', [BusinessTime::ZONE])->get()->keyBy('d');
        $expenses = DB::table('expenses')->whereBetween('spent_on', [$from, $to])->groupBy('spent_on')
            ->selectRaw("to_char(spent_on, 'YYYY-MM-DD') as d, SUM(amount_cents) as total")->get()->keyBy('d');

        $days = [];

        for ($day = $start->copy(); $day->lte($start->copy()->endOfMonth()); $day->addDay()) {
            $key = $day->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'received_cents' => $this->int($received[$key]->total ?? 0),
                'expected_cents' => $this->int($expected[$key]->expected ?? 0),
                'appointments_count' => $this->int($expected[$key]->items ?? 0),
                'expenses_cents' => $this->int($expenses[$key]->total ?? 0),
            ];
        }

        return $days;
    }

    /** Pagamentos recebidos no período (dia local) de agendamentos que não estão cancelados nem com falta. */
    private function receivedQuery(string $from, string $to): Builder
    {
        return DB::table('payments as p')
            ->join('appointments as a', function (JoinClause $join): void {
                $join->on('a.id', '=', 'p.appointment_id')->on('a.tenant_id', '=', 'p.tenant_id');
            })
            ->where('p.status', 'received')->whereIn('a.status', Appointment::ACTIVE)
            ->whereRaw('(p.paid_at AT TIME ZONE ?)::date between ? and ?', [BusinessTime::ZONE, $from, $to]);
    }

    /** Agendamentos previstos (pendente, agendado e finalizado) que começam no período (dia local). */
    private function expectedQuery(string $from, string $to): Builder
    {
        return DB::table('appointments as a')
            ->whereIn('a.status', Appointment::ACTIVE)
            ->whereRaw('(a.starts_at AT TIME ZONE ?)::date between ? and ?', [BusinessTime::ZONE, $from, $to]);
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /** A comissão já vem arredondada ao centavo por pagamento e por agendamento (meio para cima, como `AppointmentFinance`). */
    private function cents(mixed $raw): int
    {
        return $this->int($raw);
    }
}
