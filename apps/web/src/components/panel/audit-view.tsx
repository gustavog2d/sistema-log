'use client';

import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { labelOf, type AuditLog, type AuditPage } from '@/lib/audit';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField } from '@/components/ui/inputs';
import { AuditList } from './audit-list';

const LIMIT = 20;

/** Tipos de registro que aparecem no filtro (os que já têm tela). */
export const AUDIT_ENTITIES = [
  'client',
  'service',
  'service_category',
  'professional',
  'working_hours',
  'time_off',
  'appointment',
  'payment',
  'expense',
  'expense_category',
  'setting',
] as const;

/** Histórico de mudanças do negócio (dona e gerente), com filtro por tipo e "carregar mais" por cursor. */
export function AuditView({ initial }: { initial: AuditPage | null }) {
  const [logs, setLogs] = useState<AuditLog[]>(initial?.data ?? []);
  const [cursor, setCursor] = useState<string | null>(
    initial?.next_cursor ?? null,
  );
  const [type, setType] = useState('');
  // Filtro já aplicado: o "carregar mais" continua a consulta que gerou o cursor.
  const [applied, setApplied] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('audit.loadError'),
  );

  async function load(append: boolean) {
    const filter = append ? applied : type;
    const query = new URLSearchParams({ limit: String(LIMIT) });

    if (filter) query.set('entity_type', filter);
    if (append && cursor) query.set('cursor', cursor);

    setBusy(true);
    setError(null);

    try {
      const { data } = await call<AuditPage>('GET', `audit-logs?${query}`);
      setApplied(filter);
      setLogs((current) => (append ? [...current, ...data.data] : data.data));
      setCursor(data.next_cursor);
    } catch (failure) {
      setError(
        failure instanceof ApiError
          ? failure.message
          : t('common.genericError'),
      );
    } finally {
      setBusy(false);
    }
  }

  function filter(event: FormEvent) {
    event.preventDefault();
    void load(false);
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('audit.title')} description={t('audit.text')} />
      <form
        onSubmit={filter}
        role="search"
        aria-label={t('audit.title')}
        className="grid gap-4 sm:grid-cols-[16rem_auto] sm:items-end"
      >
        <SelectField
          label={t('audit.filter')}
          value={type}
          onChange={(event) => setType(event.target.value)}
        >
          <option value="">{t('audit.all')}</option>
          {AUDIT_ENTITIES.map((entity) => (
            <option key={entity} value={entity}>
              {labelOf('audit.entity', entity)}
            </option>
          ))}
        </SelectField>
        <Button type="submit" loading={busy && logs.length === 0}>
          {t('common.confirm')}
        </Button>
      </form>

      {error ? <Alert tone="danger">{error}</Alert> : null}
      {logs.length === 0 && !error ? (
        <EmptyState title={t('audit.empty')} />
      ) : (
        <AuditList logs={logs} />
      )}
      {cursor ? (
        <Button
          variant="secondary"
          loading={busy}
          onClick={() => void load(true)}
        >
          {t('audit.loadMore')}
        </Button>
      ) : null}
    </div>
  );
}
