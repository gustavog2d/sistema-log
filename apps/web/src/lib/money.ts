/** Reais digitados ("15" ou "15,90") em centavos; vazio é `null`; texto inválido é `NaN`. */
export function reaisToCents(value: string): number | null {
  const text = value.trim().replace(',', '.');

  if (text === '') return null;
  // "1.500" é ambíguo (mil e quinhentos ou um e meio?): recusa em vez de adivinhar.
  if (/^\d{1,3}(\.\d{3})+$/.test(text) && !value.includes(','))
    return Number.NaN;
  if (!/^\d+(\.\d{1,2})?$/.test(text)) return Number.NaN;

  return Math.round(Number(text) * 100);
}

export function centsToReais(cents: number | null): string {
  return cents === null ? '' : (cents / 100).toFixed(2).replace('.', ',');
}
