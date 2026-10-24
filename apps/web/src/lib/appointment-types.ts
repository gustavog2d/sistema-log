import { BUSINESS_OFFSET, BUSINESS_ZONE } from './professional-types';

export type AppointmentStatus =
  'pending' | 'scheduled' | 'completed' | 'canceled' | 'no_show';

export type Appointment = {
  id: string;
  client: { id: string; name: string };
  professional: { id: string; name: string; color: string };
  service: { id: string; name: string } | null;
  starts_at: string;
  ends_at: string;
  description: string | null;
  status: AppointmentStatus;
  type: 'appointment' | 'retouch';
  origin: string;
  session_number: number | null;
  sessions_total: number | null;
  /** Dado financeiro: vem nulo para a recepção. */
  value_total_cents: number | null;
  notes: string | null;
  client_review: number | null;
  created_at: string;
  updated_at: string;
};

export type AppointmentList = { data: Appointment[] };

export type Slot = { starts_at: string; ends_at: string };

/** Próximas situações que a tela oferece como ação (a API valida o grafo completo). */
export const NEXT_STATUS: Record<AppointmentStatus, AppointmentStatus[]> = {
  pending: ['scheduled', 'canceled'],
  scheduled: ['completed', 'no_show', 'canceled'],
  canceled: ['scheduled'],
  no_show: ['scheduled'],
  completed: [],
};

const DAY_MS = 86_400_000;

function parse(day: string): number {
  const [year, month, date] = day.split('-').map(Number);

  return Date.UTC(year ?? 1970, (month ?? 1) - 1, date ?? 1);
}

function format(ms: number): string {
  return new Date(ms).toISOString().slice(0, 10);
}

/** Soma dias a uma data `aaaa-mm-dd` (conta de calendário pura, sem fuso). */
export function addDays(day: string, days: number): string {
  return format(parse(day) + days * DAY_MS);
}

/** Segunda-feira da semana da data (a semana exibida vai de segunda a domingo). */
export function mondayOf(day: string): string {
  const weekday = new Date(parse(day)).getUTCDay();

  return addDays(day, weekday === 0 ? -6 : 1 - weekday);
}

/** Data de hoje no relógio do negócio (`aaaa-mm-dd`). */
export function todayInBusiness(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: BUSINESS_ZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now);
}

/** Período da semana para a API: da segunda 00:00 até a segunda seguinte 00:00, no fuso do negócio. */
export function weekRange(monday: string): { from: string; to: string } {
  return {
    from: `${monday}T00:00:00${BUSINESS_OFFSET}`,
    to: `${addDays(monday, 7)}T00:00:00${BUSINESS_OFFSET}`,
  };
}

/** Dia (`aaaa-mm-dd`) de um instante no relógio do negócio. */
export function dayOf(iso: string): string {
  return todayInBusiness(new Date(iso));
}

/** Hora (`HH:MM`) de um instante no relógio do negócio. */
export function timeOf(iso: string): string {
  return new Intl.DateTimeFormat('pt-BR', {
    timeZone: BUSINESS_ZONE,
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
  }).format(new Date(iso));
}

/** Título do dia, como `terça-feira, 03/11`. */
export function dayTitle(day: string): string {
  return new Intl.DateTimeFormat('pt-BR', {
    timeZone: 'UTC',
    weekday: 'long',
    day: '2-digit',
    month: '2-digit',
  }).format(new Date(parse(day)));
}
