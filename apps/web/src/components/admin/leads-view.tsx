'use client';

import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { Lead, LeadStatus, Page } from '@/lib/admin-types';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge, type Tone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextField, TextareaField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { queryString } from './list-utils';

const LIMIT = 20;
const STATUSES: LeadStatus[] = ['new', 'contacted', 'converted', 'discarded'];
const TONES: Record<LeadStatus, Tone> = {
  new: 'info',
  contacted: 'warning',
  converted: 'success',
  discarded: 'neutral',
};

/** Leads da landing: busca, filtros, mudança de status e notas; apagar só para o super admin. */
export function LeadsView({
  initial,
  canWrite,
}: {
  initial: Page<Lead> | null;
  canWrite: boolean;
}) {
  const [rows, setRows] = useState<Lead[]>(initial?.data ?? []);
  const [cursor, setCursor] = useState<string | null>(
    initial?.next_cursor ?? null,
  );
  const [q, setQ] = useState('');
  const [status, setStatus] = useState('');
  const [source, setSource] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('admin.loadError'),
  );
  const [notice, setNotice] = useState<string | null>(null);

  const [selected, setSelected] = useState<Lead | null>(null);
  const [draft, setDraft] = useState<{ status: LeadStatus; notes: string }>({
    status: 'new',
    notes: '',
  });
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [saving, setSaving] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  async function load(append: boolean) {
    setBusy(true);
    setError(null);

    try {
      const { data } = await call<Page<Lead>>(
        'GET',
        `platform/leads${queryString({ q, status, utm_source: source, limit: LIMIT, cursor: append ? cursor : null })}`,
      );
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

  function open(lead: Lead) {
    setDialogError(null);
    setFieldErrors({});
    setConfirmDelete(false);
    setDraft({ status: lead.status, notes: lead.notes ?? '' });
    setSelected(lead);
  }

  function failureText(failure: unknown): string {
    return failure instanceof ApiError && failure.status === 403
      ? t('admin.noPermission')
      : failure instanceof ApiError
        ? failure.message
        : t('common.genericError');
  }

  async function save(event: FormEvent) {
    event.preventDefault();

    if (!selected) return;

    setSaving(true);
    setDialogError(null);
    setFieldErrors({});

    try {
      const { data } = await call<Lead>(
        'PATCH',
        `platform/leads/${selected.id}`,
        {
          body: {
            status: draft.status,
            notes: draft.notes.trim() === '' ? null : draft.notes.trim(),
          },
        },
      );
      setRows((current) =>
        current.map((row) => (row.id === data.id ? data : row)),
      );
      setSelected(null);
      setNotice(t('admin.leads.saved'));
    } catch (failure) {
      if (
        failure instanceof ApiError &&
        Object.keys(failure.fieldErrors).length > 0
      ) {
        setFieldErrors(failure.fieldErrors);
      } else {
        setDialogError(failureText(failure));
      }
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (!selected) return;

    setSaving(true);
    setDialogError(null);

    try {
      await call('DELETE', `platform/leads/${selected.id}`);
      setRows((current) => current.filter((row) => row.id !== selected.id));
      setSelected(null);
      setNotice(t('admin.leads.deleted'));
    } catch (failure) {
      setDialogError(failureText(failure));
    } finally {
      setSaving(false);
    }
  }

  const origin = (lead: Lead) =>
    [lead.utm_source, lead.utm_medium, lead.utm_campaign]
      .filter(Boolean)
      .join(' / ') || '—';

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('admin.leads.title')}
        description={t('admin.leads.text')}
      />
      {notice ? <Alert tone="success">{notice}</Alert> : null}
      <form
        onSubmit={search}
        role="search"
        aria-label={t('admin.leads.searchLabel')}
        className="grid gap-4 sm:grid-cols-[1fr_12rem_12rem_auto] sm:items-end"
      >
        <TextField
          label={t('admin.leads.search')}
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
          {STATUSES.map((value) => (
            <option key={value} value={value}>
              {t(`admin.leads.${value}`)}
            </option>
          ))}
        </SelectField>
        <TextField
          label={t('admin.leads.source')}
          value={source}
          onChange={(event) => setSource(event.target.value)}
          maxLength={100}
          autoComplete="off"
        />
        <Button type="submit" loading={busy && rows.length === 0}>
          {t('admin.tenants.apply')}
        </Button>
      </form>

      {error ? <Alert tone="danger">{error}</Alert> : null}

      {rows.length === 0 && !error ? (
        <EmptyState title={t('admin.leads.empty')} />
      ) : (
        <Table caption={t('admin.leads.caption')}>
          <thead>
            <Tr>
              <Th>{t('admin.leads.name')}</Th>
              <Th>{t('admin.leads.email')}</Th>
              <Th>{t('admin.tenants.situation')}</Th>
              <Th>{t('admin.leads.origin')}</Th>
              <Th>{t('admin.leads.date')}</Th>
              <Th>{t('admin.actions')}</Th>
            </Tr>
          </thead>
          <tbody>
            {rows.map((lead) => (
              <Tr key={lead.id}>
                <Td>{lead.name}</Td>
                <Td>{lead.email}</Td>
                <Td>
                  <Badge tone={TONES[lead.status]}>
                    {t(`admin.leads.${lead.status}`)}
                  </Badge>
                </Td>
                <Td>{origin(lead)}</Td>
                <Td>{formatDate(lead.created_at)}</Td>
                <Td>
                  <Button
                    variant="secondary"
                    onClick={() => open(lead)}
                    aria-label={`${t('admin.leads.open')}: ${lead.name}`}
                  >
                    {t('admin.leads.open')}
                  </Button>
                </Td>
              </Tr>
            ))}
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

      <Dialog
        open={selected !== null}
        onClose={() => setSelected(null)}
        title={
          selected ? tf('admin.leads.dialogTitle', { name: selected.name }) : ''
        }
        description={selected?.email}
      >
        {selected ? (
          <form
            onSubmit={(event) => void save(event)}
            noValidate
            className="flex flex-col gap-4"
          >
            {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
              <dt className="text-muted">{t('admin.leads.phone')}</dt>
              <dd>{selected.phone ?? '—'}</dd>
              <dt className="text-muted">{t('admin.leads.niche')}</dt>
              <dd>{selected.niche ?? '—'}</dd>
              <dt className="text-muted">{t('admin.leads.origin')}</dt>
              <dd>{origin(selected)}</dd>
              <dt className="text-muted">{t('admin.leads.message')}</dt>
              <dd className="whitespace-pre-wrap break-words">
                {selected.message ?? '—'}
              </dd>
            </dl>
            <SelectField
              label={t('admin.tenants.situation')}
              value={draft.status}
              onChange={(event) =>
                setDraft({ ...draft, status: event.target.value as LeadStatus })
              }
              error={fieldErrors.status}
            >
              {STATUSES.map((value) => (
                <option key={value} value={value}>
                  {t(`admin.leads.${value}`)}
                </option>
              ))}
            </SelectField>
            <TextareaField
              label={t('admin.leads.notes')}
              value={draft.notes}
              onChange={(event) =>
                setDraft({ ...draft, notes: event.target.value })
              }
              error={fieldErrors.notes}
              maxLength={1000}
            />
            {confirmDelete ? (
              <Alert tone="warning">
                <p>{t('admin.leads.deleteWarning')}</p>
                <div className="mt-2 flex gap-2">
                  <Button
                    variant="danger"
                    loading={saving}
                    onClick={() => void remove()}
                  >
                    {t('admin.leads.deleteConfirm')}
                  </Button>
                  <Button
                    variant="secondary"
                    onClick={() => setConfirmDelete(false)}
                    disabled={saving}
                  >
                    {t('common.cancel')}
                  </Button>
                </div>
              </Alert>
            ) : null}
            <div className="flex flex-wrap justify-between gap-2">
              {canWrite && !confirmDelete ? (
                <Button
                  variant="secondary"
                  onClick={() => setConfirmDelete(true)}
                  disabled={saving}
                >
                  {t('admin.leads.delete')}
                </Button>
              ) : (
                <span />
              )}
              <span className="flex gap-2">
                <Button
                  variant="secondary"
                  onClick={() => setSelected(null)}
                  disabled={saving}
                >
                  {t('common.cancel')}
                </Button>
                <Button type="submit" loading={saving}>
                  {t('admin.dialog.save')}
                </Button>
              </span>
            </div>
          </form>
        ) : null}
      </Dialog>
    </div>
  );
}
