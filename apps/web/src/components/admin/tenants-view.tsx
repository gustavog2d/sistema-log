'use client';

import Link from 'next/link';
import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { Page, TenantItem } from '@/lib/admin-types';
import { formatBRL, formatDate } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { queryString } from './list-utils';
import { TenantStatusBadge } from './tenant-status';

const LIMIT = 20;

/** Próxima data que importa ao negócio: fim do teste ou fim do período. */
function nextDate(tenant: TenantItem): string | null {
  return tenant.status === 'trialing'
    ? tenant.trial_ends_at
    : tenant.current_period_end;
}

/** Lista de assinantes com busca, filtro de situação e "carregar mais" por cursor. */
export function TenantsView({ initial }: { initial: Page<TenantItem> | null }) {
  const [rows, setRows] = useState<TenantItem[]>(initial?.data ?? []);
  const [cursor, setCursor] = useState<string | null>(
    initial?.next_cursor ?? null,
  );
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  // Filtros já aplicados: o "carregar mais" continua a busca que gerou o cursor, não o que está digitado.
  const [applied, setApplied] = useState({ q: '', status: '' });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('admin.loadError'),
  );

  async function load(append: boolean) {
    const filters = append ? applied : { q, status };

    setBusy(true);
    setError(null);

    try {
      const { data } = await call<Page<TenantItem>>(
        'GET',
        `platform/tenants${queryString({ ...filters, limit: LIMIT, cursor: append ? cursor : null })}`,
      );
      setApplied(filters);
      setRows((current) => (append ? [...current, ...data.data] : data.data));
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

  function search(event: FormEvent) {
    event.preventDefault();
    void load(false);
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('admin.tenants.title')}
        description={t('admin.tenants.text')}
      />
      <form
        onSubmit={search}
        role="search"
        aria-label={t('admin.tenants.searchLabel')}
        className="grid gap-4 sm:grid-cols-[1fr_14rem_auto] sm:items-end"
      >
        <TextField
          label={t('admin.tenants.search')}
          value={q}
          onChange={(event) => setQ(event.target.value)}
          maxLength={100}
          autoComplete="off"
        />
        <SelectField
          label={t('admin.tenants.statusFilter')}
          value={status}
          onChange={(event) => setStatus(event.target.value)}
        >
          <option value="">{t('admin.tenants.all')}</option>
          {(['trialing', 'active', 'suspended', 'canceled'] as const).map(
            (value) => (
              <option key={value} value={value}>
                {t(`status.${value}`)}
              </option>
            ),
          )}
        </SelectField>
        <Button type="submit" loading={busy && rows.length === 0}>
          {t('admin.tenants.apply')}
        </Button>
      </form>

      {error ? <Alert tone="danger">{error}</Alert> : null}

      {rows.length === 0 && !error ? (
        <EmptyState title={t('admin.tenants.empty')} />
      ) : (
        <Table caption={t('admin.tenants.caption')}>
          <thead>
            <Tr>
              <Th>{t('admin.tenants.name')}</Th>
              <Th>{t('admin.tenants.address')}</Th>
              <Th>{t('admin.tenants.plan')}</Th>
              <Th>{t('admin.tenants.situation')}</Th>
              <Th>{t('admin.tenants.price')}</Th>
              <Th>{t('admin.tenants.nextDate')}</Th>
            </Tr>
          </thead>
          <tbody>
            {rows.map((tenant) => {
              const date = nextDate(tenant);

              return (
                <Tr key={tenant.id}>
                  <Td>
                    <Link
                      href={`/admin/assinantes/${tenant.id}`}
                      className="font-medium text-brand-700 underline"
                    >
                      {tenant.name}
                    </Link>
                  </Td>
                  <Td>{tenant.slug}</Td>
                  <Td>{tenant.plan ?? '—'}</Td>
                  <Td>
                    <TenantStatusBadge tenant={tenant} />
                  </Td>
                  <Td>
                    {tenant.price_cents === null
                      ? '—'
                      : formatBRL(tenant.price_cents)}
                  </Td>
                  <Td>{date ? formatDate(date) : '—'}</Td>
                </Tr>
              );
            })}
          </tbody>
        </Table>
      )}

      {cursor ? (
        <Button
          variant="secondary"
          loading={busy}
          onClick={() => void load(true)}
        >
          {t('admin.loadMore')}
        </Button>
      ) : null}
    </div>
  );
}
