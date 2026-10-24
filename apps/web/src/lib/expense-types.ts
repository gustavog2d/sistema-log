export type ExpenseCategory = {
  id: string;
  name: string;
  expenses_count: number;
  created_at: string;
  updated_at: string;
};

export type Expense = {
  id: string;
  category: { id: string; name: string };
  description: string;
  amount_cents: number;
  /** Dia do gasto (aaaa-mm-dd) no calendário do negócio. */
  spent_on: string;
  created_at: string;
  updated_at: string;
};

export type ExpenseList = {
  data: Expense[];
  /** Soma e quantidade do filtro inteiro (não só da página). */
  total_cents: number;
  count: number;
  by_category: Array<{
    category_id: string;
    name: string;
    total_cents: number;
  }>;
};

/** Soma meses a `aaaa-mm` (conta de calendário pura). */
export function addMonths(month: string, delta: number): string {
  const [year, mon] = month.split('-').map(Number);
  const index = (year ?? 1970) * 12 + ((mon ?? 1) - 1) + delta;

  return `${Math.floor(index / 12)}-${String((index % 12) + 1).padStart(2, '0')}`;
}

/** Primeiro e último dia do mês (`aaaa-mm`), para o período da API. */
export function monthRange(month: string): { from: string; to: string } {
  const [year, mon] = month.split('-').map(Number);
  const last = new Date(Date.UTC(year ?? 1970, mon ?? 1, 0)).getUTCDate();

  return {
    from: `${month}-01`,
    to: `${month}-${String(last).padStart(2, '0')}`,
  };
}

/** `2026-11` vira `novembro de 2026`. */
export function monthTitle(month: string): string {
  const [year, mon] = month.split('-').map(Number);

  return new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
    month: 'long',
    year: 'numeric',
  }).format(new Date(Date.UTC(year ?? 1970, (mon ?? 1) - 1, 1)));
}

/** `2026-11-05` vira `05/11/2026`. */
export function formatDay(day: string): string {
  const [year, mon, date] = day.split('-');

  return `${date}/${mon}/${year}`;
}
