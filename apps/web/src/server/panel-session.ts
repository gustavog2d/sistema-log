import { cache } from 'react';
import { getPanelContext } from './request-context';
import { loadPanelSession } from './session';

/** Sessão do pedido atual, lida uma vez só mesmo que a moldura e a página peçam (cache por pedido do React). */
export const getPanelSession = cache(async () =>
  loadPanelSession(await getPanelContext()),
);
