'use client';

import { useEffect, useState } from 'react';
import { t } from '@/i18n';
import { call } from '@/lib/api';
import { Alert } from '@/components/ui/alert';
import { Card } from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';

/**
 * Entrada do acesso de suporte: o token chega no fragmento da URL (`#token=…`, que nunca vai ao servidor), é entregue à
 * ponte, o fragmento é apagado na hora e o painel abre com a faixa de suporte.
 */
export function SupportEntry({
  go = (path: string) => window.location.assign(path),
}: {
  go?: (path: string) => void;
}) {
  const [state, setState] = useState<'working' | 'invalid' | 'missing'>(
    'working',
  );

  useEffect(() => {
    const token = new URLSearchParams(
      window.location.hash.replace(/^#/, ''),
    ).get('token');
    // O fragmento sai da barra de endereço antes de qualquer chamada.
    window.history.replaceState(null, '', window.location.pathname);

    // O estado só muda dentro da resposta (assíncrona): "sem token" também passa por aqui.
    (token
      ? call('POST', 'session/adopt', { body: { token } })
      : Promise.reject(new Error('missing'))
    ).then(
      () => go('/'),
      (error: unknown) =>
        setState(
          error instanceof Error && error.message === 'missing'
            ? 'missing'
            : 'invalid',
        ),
    );
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  return (
    <Card className="mx-auto w-full max-w-md">
      <h1 className="text-2xl font-bold text-ink">{t('support.title')}</h1>
      <div className="mt-4">
        {state === 'working' ? (
          <p className="flex items-center gap-2 text-muted">
            <Spinner /> {t('support.working')}
          </p>
        ) : (
          <Alert tone="danger">
            {state === 'invalid' ? t('support.invalid') : t('support.missing')}
          </Alert>
        )}
      </div>
    </Card>
  );
}
