import { cookies, headers } from 'next/headers';
import { SESSION_COOKIE } from './bff';
import type { PanelContext } from './session';

/** Endereço e sessão do pedido atual (só nas páginas do servidor). */
export async function getPanelContext(): Promise<PanelContext> {
  const [requestHeaders, jar] = await Promise.all([headers(), cookies()]);

  return {
    host: requestHeaders.get('host') ?? '',
    token: jar.get(SESSION_COOKIE)?.value ?? null,
  };
}
