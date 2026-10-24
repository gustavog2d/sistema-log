export type Professional = {
  id: string;
  name: string;
  color: string;
  commission_percent: number;
  user_id: string | null;
  status: 'active' | 'inactive';
  created_at: string;
  updated_at: string;
};

export type ProfessionalPage = {
  data: Professional[];
  next_cursor: string | null;
};

export type WorkingRange = { weekday: number; start: string; end: string };

export type TimeOff = {
  id: string;
  professional_id: string | null;
  starts_at: string;
  ends_at: string;
  reason: string | null;
  created_at: string;
  updated_at: string;
};

export type TimeOffPage = { data: TimeOff[]; next_cursor: string | null };

const ZONE = 'America/Sao_Paulo';

/** `2026-12-24T03:00:00+00:00` vira `2026-12-24T00:00` (relógio do negócio) para o campo de data e hora. */
export function toLocalInput(iso: string): string {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone: ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).formatToParts(new Date(iso));
  const get = (type: string) =>
    parts.find((part) => part.type === type)?.value ?? '00';

  return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

/** `2026-12-24T00:00` (relógio do negócio) vira um instante ISO com o fuso de Brasília. */
export function fromLocalInput(value: string): string {
  return `${value}:00-03:00`;
}

/** Dias da semana na ordem de exibição (segunda a domingo); o número é o da API (0 = domingo). */
export const WEEK_ORDER = [1, 2, 3, 4, 5, 6, 0] as const;
