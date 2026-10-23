/** Dinheiro em centavos (inteiro) para texto em reais. */
export function formatBRL(cents: number): string {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(cents / 100);
}

/** Data ISO 8601 para texto curto (dd/mm/aaaa), no fuso do navegador. */
export function formatDate(iso: string): string {
  return new Intl.DateTimeFormat('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(iso));
}

/** Quanto o plano anual custa a menos do que 12 meses no mensal (em centavos, nunca negativo). */
export function yearlySavings(
  monthlyCents: number,
  yearlyCents: number,
): number {
  return Math.max(0, monthlyCents * 12 - yearlyCents);
}
