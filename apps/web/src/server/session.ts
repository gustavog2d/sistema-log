import type { Me, Subscription } from '@/lib/panel-types';
import { hostAwareFetch } from './upstream';

export type PanelContext = {
  /** Endereço do negócio como veio no pedido (`estudio.localhost:3000`). */
  host: string;
  token: string | null;
  apiUrl?: string;
  fetch?: typeof fetch;
};

export type ApiResult<T> = { status: number; data: T | null };

/** Chama a API como a pessoa da sessão, usando o endereço do negócio (a API acha o negócio por ele). */
export async function apiGet<T>(
  path: string,
  context: PanelContext,
): Promise<ApiResult<T>> {
  const base = (
    context.apiUrl ??
    process.env.API_URL ??
    'http://localhost:8000'
  ).replace(/\/$/, '');
  const headers: Record<string, string> = {
    accept: 'application/json',
    host: context.host.replace(/:\d+$/, ''),
  };

  if (context.token) {
    headers.authorization = `Bearer ${context.token}`;
  }

  try {
    const response = await (context.fetch ?? hostAwareFetch)(
      `${base}/api/v1/${path}`,
      {
        method: 'GET',
        headers,
        signal: AbortSignal.timeout(8000),
      },
    );
    const text = await response.text();

    return {
      status: response.status,
      data: text ? (JSON.parse(text) as T) : null,
    };
  } catch {
    return { status: 0, data: null };
  }
}

export type PanelSession =
  | { kind: 'ok'; me: Me; subscription: Subscription | null }
  | { kind: 'unauthenticated' }
  | { kind: 'error' };

/** Sessão do painel: quem é a pessoa e como está a assinatura. 401 ou falta de cookie = sem sessão; falha da API = erro. */
export async function loadPanelSession(
  context: PanelContext,
): Promise<PanelSession> {
  if (!context.token) {
    return { kind: 'unauthenticated' };
  }

  const [me, subscription] = await Promise.all([
    apiGet<Me>('auth/me', context),
    apiGet<Subscription>('subscription', context),
  ]);

  if (me.status === 401) {
    return { kind: 'unauthenticated' };
  }

  if (me.status !== 200 || !me.data) {
    return { kind: 'error' };
  }

  return {
    kind: 'ok',
    me: me.data,
    subscription: subscription.status === 200 ? subscription.data : null,
  };
}
