import { cache } from 'react';
import type { AdminMe } from '@/lib/admin-types';
import { getPanelContext } from './request-context';
import { apiGet, type PanelContext } from './session';

export type AdminSession =
  { kind: 'ok'; me: AdminMe } | { kind: 'unauthenticated' } | { kind: 'error' };

/** Sessão da equipe da plataforma: quem é a pessoa (`/platform/me`). 401/403 ou falta de cookie = sem sessão. */
export async function loadAdminSession(
  context: PanelContext,
): Promise<AdminSession> {
  if (!context.token) {
    return { kind: 'unauthenticated' };
  }

  const me = await apiGet<AdminMe>('platform/me', context);

  if (me.status === 401 || me.status === 403) {
    return { kind: 'unauthenticated' };
  }

  if (me.status !== 200 || !me.data) {
    return { kind: 'error' };
  }

  return { kind: 'ok', me: me.data };
}

export const getAdminSession = cache(async () =>
  loadAdminSession(await getPanelContext()),
);
