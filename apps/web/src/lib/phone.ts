/** Países do seletor de telefone (DDI e tamanho típico do número nacional). O Brasil tem máscara própria. */
export const COUNTRIES = [
  { code: 'BR', ddi: '55', name: 'Brasil' },
  { code: 'PT', ddi: '351', name: 'Portugal' },
  { code: 'US', ddi: '1', name: 'Estados Unidos / Canadá' },
  { code: 'AR', ddi: '54', name: 'Argentina' },
  { code: 'UY', ddi: '598', name: 'Uruguai' },
  { code: 'PY', ddi: '595', name: 'Paraguai' },
  { code: 'CL', ddi: '56', name: 'Chile' },
  { code: 'ES', ddi: '34', name: 'Espanha' },
] as const;

export type Country = (typeof COUNTRIES)[number];

export const DEFAULT_COUNTRY: Country = COUNTRIES[0];

/** Só os dígitos. */
export function digitsOf(value: string): string {
  return value.replace(/\D/g, '');
}

/** Número nacional com a máscara do país (Brasil: `(11) 99999-9999` ou `(11) 9999-9999`); os demais, só dígitos agrupados. */
export function formatNational(country: Country, value: string): string {
  const digits = digitsOf(value).slice(0, country.code === 'BR' ? 11 : 12);

  if (country.code !== 'BR') {
    return digits.replace(/(\d{3})(?=\d)/g, '$1 ').trim();
  }

  if (digits.length <= 2) return digits.length ? `(${digits}` : '';
  if (digits.length <= 6) return `(${digits.slice(0, 2)}) ${digits.slice(2)}`;
  if (digits.length <= 10) {
    return `(${digits.slice(0, 2)}) ${digits.slice(2, 6)}-${digits.slice(6)}`;
  }

  return `(${digits.slice(0, 2)}) ${digits.slice(2, 7)}-${digits.slice(7)}`;
}

/** `+5511999998888` a partir do país e do número digitado (sem o 0 de tronco). */
export function toE164(country: Country, national: string): string {
  return `+${country.ddi}${digitsOf(national).replace(/^0+/, '')}`;
}

/** Separa um E.164 em país e número nacional (o DDI mais longo vence; desconhecido cai no Brasil com o número todo). */
export function splitE164(phone: string): {
  country: Country;
  national: string;
} {
  const digits = digitsOf(phone);
  const match = [...COUNTRIES]
    .sort((a, b) => b.ddi.length - a.ddi.length)
    .find((country) => digits.startsWith(country.ddi));

  return match
    ? { country: match, national: digits.slice(match.ddi.length) }
    : { country: DEFAULT_COUNTRY, national: digits };
}

/** Telefone para listas: `+55 (11) 99999-8888`. */
export function displayPhone(phone: string): string {
  const { country, national } = splitE164(phone);

  return `+${country.ddi} ${formatNational(country, national)}`;
}
