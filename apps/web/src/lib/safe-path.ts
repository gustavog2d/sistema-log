/** Caminho de retorno depois do login: só páginas internas do painel (nada de endereço de outro site). */
const ALLOWED = /^\/(assinatura|equipe|conta)?$/;

export function safeReturnPath(value: string | null | undefined): string {
  return value && ALLOWED.test(value) ? value : '/';
}
