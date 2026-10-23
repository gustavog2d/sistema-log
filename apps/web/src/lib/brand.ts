/** Nome do produto: sempre de configuração (o nome definitivo ainda não foi escolhido). */
export function appName(): string {
  return process.env.NEXT_PUBLIC_APP_NAME || 'App';
}

/** Iniciais para o monograma provisório do logotipo. */
export function monogram(name: string): string {
  const letters = name
    .split(/\s+/)
    .filter(Boolean)
    .map((word) => word[0]!.toUpperCase());
  return (letters.slice(0, 2).join('') || 'A').slice(0, 2);
}
