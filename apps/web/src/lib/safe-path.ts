/** Caminho de retorno depois do login: só páginas internas do painel (nada de endereço de outro site). */
const ALLOWED = /^\/(assinatura|equipe|conta)?$/;

export function safeReturnPath(value: string | null | undefined): string {
  return value && ALLOWED.test(value) ? value : '/';
}

/** Retorno do painel da plataforma: só páginas internas de `/admin` (detalhe de assinante inclui o UUID). */
const ADMIN_ALLOWED =
  /^\/admin(\/(assinantes(\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})?|planos|cupons|leads))?$/;

export function safeAdminReturnPath(value: string | null | undefined): string {
  return value && ADMIN_ALLOWED.test(value) ? value : '/admin';
}
