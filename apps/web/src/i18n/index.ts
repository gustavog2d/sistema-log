import ptBR from './pt-BR.json';

type Messages = typeof ptBR;
type Leaves<T, P extends string = ''> = {
  [K in keyof T & string]: T[K] extends string
    ? `${P}${K}`
    : T[K] extends readonly unknown[]
      ? never
      : Leaves<T[K], `${P}${K}.`>;
}[keyof T & string];

export type MessageKey = Leaves<Messages>;

function lookup(path: string): unknown {
  return path
    .split('.')
    .reduce<unknown>(
      (node, part) => (node as Record<string, unknown> | undefined)?.[part],
      ptBR,
    );
}

export function t(key: MessageKey): string {
  return lookup(key) as string;
}

/** Texto com valores: `tf('signup.passwordMissing', { n: 3 })` troca `{n}` por 3. */
export function tf(
  key: MessageKey,
  values: Record<string, string | number>,
): string {
  return t(key).replace(/\{(\w+)\}/g, (_match, name: string) =>
    String(values[name] ?? ''),
  );
}

/** Lista de textos (itens repetidos de uma página). */
export function tList<T>(path: string): T[] {
  const value = lookup(path);

  return Array.isArray(value) ? (value as T[]) : [];
}

/** Texto por chave dinâmica (ex.: nome de uma funcionalidade vinda da API), com reserva. */
export function tDynamic(path: string, fallback: string): string {
  const value = lookup(path);

  return typeof value === 'string' ? value : fallback;
}
