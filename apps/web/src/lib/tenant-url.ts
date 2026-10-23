/**
 * Endereço do painel de um negócio: o mesmo domínio e a mesma porta da página atual, com o negócio como subdomínio
 * (`localhost:3000` vira `estudio.localhost:3000`).
 */
export function tenantUrl(
  slug: string,
  location: { protocol: string; host: string },
  path = '/',
): string {
  const host = location.host.replace(/^www\./, '');

  return `${location.protocol}//${slug}.${host}${path}`;
}
