import { useSyncExternalStore } from 'react';

const subscribe = () => () => {};

/** Protocolo e endereço (com porta) da página, ou `null` no servidor: evita diferença entre o HTML do servidor e o do navegador. */
export function useLocation(): { protocol: string; host: string } | null {
  const key = useSyncExternalStore(
    subscribe,
    () => `${window.location.protocol}//${window.location.host}`,
    () => '',
  );

  if (!key) {
    return null;
  }

  const [protocol, host] = key.split('//') as [string, string];

  return { protocol, host };
}
