export type HostKind =
  { kind: 'site' } | { kind: 'tenant'; slug: string } | { kind: 'invalid' };

const SLUG = /^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])$/;

/** Subdomínios da plataforma: a API os trata como hosts da equipe ou do sistema, nunca como negócio. */
const RESERVED = new Set(['www', 'app', 'admin', 'api']);

/** Tira a porta e as maiúsculas do cabeçalho Host. */
export function hostname(hostHeader: string | null | undefined): string {
  return (hostHeader ?? '').trim().toLowerCase().replace(/:\d+$/, '');
}

/**
 * O domínio base (e `www.`) serve o site e o painel da equipe; `{negócio}.{domínio base}` serve o painel do assinante.
 * Qualquer outro endereço, ou mais de um nível de subdomínio, é inválido.
 */
export function classifyHost(
  hostHeader: string | null | undefined,
  baseDomain: string,
): HostKind {
  const host = hostname(hostHeader);
  const base = baseDomain.toLowerCase();

  if (host === base || host === `www.${base}`) {
    return { kind: 'site' };
  }

  if (host.endsWith(`.${base}`)) {
    const slug = host.slice(0, -(base.length + 1));

    return SLUG.test(slug) && !RESERVED.has(slug)
      ? { kind: 'tenant', slug }
      : { kind: 'invalid' };
  }

  return { kind: 'invalid' };
}
