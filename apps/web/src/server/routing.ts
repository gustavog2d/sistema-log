import { classifyHost } from './host';

export type RouteDecision =
  | { action: 'next' }
  | { action: 'rewrite'; pathname: string }
  | { action: 'notFound' };

/** Caminhos que não dependem do endereço: arquivos do framework, ponte com a API e ícone. */
const SHARED = [/^\/_next(\/|$)/, /^\/bff(\/|$)/, /^\/favicon\.ico$/];

/**
 * Decide, pelo endereço, qual grupo de páginas atende o pedido (RN-08):
 * o site e o painel da equipe no domínio base; o painel do assinante (`/t/...`) nos endereços de assinante.
 * O cruzamento (assinante pedindo página do site, ou o site pedindo `/t`) é 404.
 */
export function decideRoute(
  hostHeader: string | null | undefined,
  pathname: string,
  baseDomain: string,
): RouteDecision {
  if (SHARED.some((pattern) => pattern.test(pathname))) {
    return { action: 'next' };
  }

  const host = classifyHost(hostHeader, baseDomain);

  if (host.kind === 'invalid') {
    return { action: 'notFound' };
  }

  const internal = pathname === '/t' || pathname.startsWith('/t/');

  if (host.kind === 'site') {
    return internal ? { action: 'notFound' } : { action: 'next' };
  }

  // Endereço de assinante: o painel da equipe e o cadastro do site não existem aqui.
  if (internal || pathname === '/admin' || pathname.startsWith('/admin/')) {
    return { action: 'notFound' };
  }

  return {
    action: 'rewrite',
    pathname: pathname === '/' ? '/t' : `/t${pathname}`,
  };
}
