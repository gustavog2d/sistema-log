'use client';

import { useEffect, useState } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { AuditLog, AuditPage } from '@/lib/audit';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { AuditList } from './audit-list';

type State =
  | { kind: 'loading' }
  | { kind: 'error'; message: string }
  | { kind: 'ready'; logs: AuditLog[]; cursor: string | null };

type Loaded = { logs: AuditLog[]; cursor: string | null } | { error: string };

/** Busca uma página do histórico do registro; devolve o erro já traduzido em vez de lançar. */
async function fetchHistory(
  entityType: string,
  entityId: string,
  cursor: string | null,
): Promise<Loaded> {
  const query = new URLSearchParams({
    entity_type: entityType,
    entity_id: entityId,
    limit: '20',
  });

  if (cursor) query.set('cursor', cursor);

  try {
    const { data } = await call<AuditPage>('GET', `audit-logs?${query}`);

    return { logs: data.data, cursor: data.next_cursor };
  } catch (failure) {
    return {
      error:
        failure instanceof ApiError && failure.status === 403
          ? t('panel.noPermission')
          : failure instanceof ApiError
            ? failure.message
            : t('common.genericError'),
    };
  }
}

/** Histórico de um registro (cliente, agendamento…): as mudanças dele, do mais novo para o mais antigo. Só dona e gerente (a API decide). */
export function EntityHistory({
  entityType,
  entityId,
}: {
  entityType: string;
  entityId: string;
}) {
  const [state, setState] = useState<State>({ kind: 'loading' });
  const [more, setMore] = useState(false);

  useEffect(() => {
    let current = true;

    void fetchHistory(entityType, entityId, null).then((result) => {
      if (current) {
        setState(
          'error' in result
            ? { kind: 'error', message: result.error }
            : { kind: 'ready', ...result },
        );
      }
    });

    return () => {
      current = false;
    };
  }, [entityType, entityId]);

  async function loadMore(cursor: string) {
    setMore(true);

    const result = await fetchHistory(entityType, entityId, cursor);

    setState((current) =>
      'error' in result
        ? { kind: 'error', message: result.error }
        : {
            kind: 'ready',
            logs: [
              ...(current.kind === 'ready' ? current.logs : []),
              ...result.logs,
            ],
            cursor: result.cursor,
          },
    );
    setMore(false);
  }

  return (
    <section aria-label={t('audit.historyTitle')} className="space-y-3">
      {state.kind === 'loading' ? (
        <p className="flex items-center gap-2 text-muted">
          <Spinner /> {t('common.loading')}
        </p>
      ) : null}
      {state.kind === 'error' ? (
        <Alert tone="danger">{state.message}</Alert>
      ) : null}
      {state.kind === 'ready' && state.logs.length === 0 ? (
        <p className="text-muted">{t('audit.historyEmpty')}</p>
      ) : null}
      {state.kind === 'ready' && state.logs.length > 0 ? (
        <AuditList logs={state.logs} showEntity={false} />
      ) : null}
      {state.kind === 'ready' && state.cursor ? (
        <Button
          variant="secondary"
          loading={more}
          onClick={() => void loadMore(state.cursor!)}
        >
          {t('audit.loadMore')}
        </Button>
      ) : null}
    </section>
  );
}
