import { t, tf } from '@/i18n';
import { ApiError } from '@/lib/api';

/** Aviso de bloqueio por tentativas erradas, com o tempo quando a API informa. */
export function lockedText(error: unknown): string {
  const seconds = error instanceof ApiError ? error.retryAfter : undefined;

  return seconds && seconds > 0
    ? tf('login.lockedFor', { minutes: Math.ceil(seconds / 60) })
    : t('login.locked');
}
