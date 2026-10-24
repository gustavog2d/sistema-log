export type FinanceSummary = {
  received_cents: number;
  expected_cents: number;
  to_receive_cents: number;
  commission_received_cents: number;
  net_received_cents: number;
  commission_expected_cents: number;
  net_expected_cents: number;
  expenses_cents: number;
  result_cents: number;
  appointments_count: number;
  by_method: Array<{ method: string; total_cents: number }>;
};

export type FinanceMonth = {
  month: string;
  received_cents: number;
  net_received_cents: number;
  expected_cents: number;
  expenses_cents: number;
  result_cents: number;
};

export type FinanceMonthly = {
  months: FinanceMonth[];
  totals: Omit<FinanceMonth, 'month'>;
};

export type FinanceDay = {
  date: string;
  received_cents: number;
  expected_cents: number;
  appointments_count: number;
  expenses_cents: number;
};

export type FinanceCalendar = { month: string; days: FinanceDay[] };

export type FinanceData = {
  summary: FinanceSummary;
  monthly: FinanceMonthly;
  calendar: FinanceCalendar;
};

/** Percentual da meta já alcançado (pode passar de 100); `null` sem meta. */
export function goalProgress(
  received: number,
  goal: number | null,
): number | null {
  return goal && goal > 0 ? Math.round((received / goal) * 100) : null;
}

/** Posição do dia da semana com segunda = 0 (`aaaa-mm-dd`), para montar o calendário em semanas. */
export function weekdayIndex(day: string): number {
  const [year, mon, date] = day.split('-').map(Number);
  const weekday = new Date(
    Date.UTC(year ?? 1970, (mon ?? 1) - 1, date ?? 1),
  ).getUTCDay();

  return (weekday + 6) % 7;
}

/** Os dias do mês divididos em semanas de segunda a domingo; as posições vazias são `null`. */
export function weeksOf(days: FinanceDay[]): Array<Array<FinanceDay | null>> {
  const first = days[0];

  if (!first) return [];

  const cells: Array<FinanceDay | null> = [
    ...Array.from({ length: weekdayIndex(first.date) }, () => null),
    ...days,
  ];

  while (cells.length % 7 !== 0) cells.push(null);

  return Array.from({ length: cells.length / 7 }, (_, week) =>
    cells.slice(week * 7, week * 7 + 7),
  );
}
