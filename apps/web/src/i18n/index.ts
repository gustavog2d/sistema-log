import ptBR from './pt-BR.json';

type Messages = typeof ptBR;
type Leaves<T, P extends string = ''> = {
  [K in keyof T & string]: T[K] extends string
    ? `${P}${K}`
    : Leaves<T[K], `${P}${K}.`>;
}[keyof T & string];

export type MessageKey = Leaves<Messages>;

export function t(key: MessageKey): string {
  const value = key
    .split('.')
    .reduce<unknown>(
      (node, part) => (node as Record<string, unknown>)[part],
      ptBR,
    );
  return value as string;
}
