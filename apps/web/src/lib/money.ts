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
