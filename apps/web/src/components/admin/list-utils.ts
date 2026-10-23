/** Monta a query string só com os campos preenchidos. */
export function queryString(
  params: Record<string, string | number | null | undefined>,
): string {
  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined && String(value).trim() !== '') {
      search.set(key, String(value).trim());
    }
  }

  const text = search.toString();

  return text ? `?${text}` : '';
}

const ZONE = 'America/Sao_Paulo';

/** `AAAA-MM-DD` no fuso do negócio, para o campo de data. */
export function toDateInput(iso: string | null): string {
  return iso
    ? new Intl.DateTimeFormat('en-CA', { timeZone: ZONE }).format(new Date(iso))
    : '';
}

/** Início ou fim do dia (fuso de Brasília) em ISO 8601, ou `null` sem data. */
export function fromDateInput(
  value: string,
  edge: 'start' | 'end',
): string | null {
  return value
    ? `${value}T${edge === 'start' ? '00:00:00' : '23:59:59'}-03:00`
    : null;
}

/** Reais digitados ("15" ou "15,90") em centavos; vazio é `null`; texto inválido é `NaN`. */
export function reaisToCents(value: string): number | null {
  const text = value.trim().replace(',', '.');

  if (text === '') return null;
  if (!/^\d+(\.\d{1,2})?$/.test(text)) return Number.NaN;

  return Math.round(Number(text) * 100);
}

export function centsToReais(cents: number | null): string {
  return cents === null ? '' : (cents / 100).toFixed(2).replace('.', ',');
}

/** Primeira mensagem de erro de campo que não tem campo na tela (para não deixar o diálogo mudo). */
export function unmatchedFieldError(
  fieldErrors: Record<string, string>,
  shown: string[],
): string | null {
  const key = Object.keys(fieldErrors).find((name) => !shown.includes(name));

  return key ? (fieldErrors[key] ?? null) : null;
}
