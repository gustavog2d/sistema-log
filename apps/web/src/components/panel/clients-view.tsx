'use client';

import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { Client, ClientPage } from '@/lib/client-types';
import {
  COUNTRIES,
  DEFAULT_COUNTRY,
  displayPhone,
  formatNational,
  splitE164,
  toE164,
  type Country,
} from '@/lib/phone';
import type { Me } from '@/lib/panel-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextField, TextareaField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { EntityHistory } from './entity-history';

const LIMIT = 20;

type Form = {
  name: string;
  country: Country;
  national: string;
  email: string;
  instagram: string;
  notes: string;
};

const EMPTY: Form = {
  name: '',
  country: DEFAULT_COUNTRY,
  national: '',
  email: '',
  instagram: '',
  notes: '',
};

type Dialogs =
  | { kind: 'form'; client: Client | null }
  | { kind: 'delete'; client: Client }
  | { kind: 'history'; client: Client }
  | null;

/** Clientes do negócio: busca, cadastro, edição, exclusão (dona e gerente) e histórico do cliente (dona e gerente). */
export function ClientsView({
  me,
  initial,
}: {
  me: Me;
  initial: ClientPage | null;
}) {
  const [rows, setRows] = useState<Client[]>(initial?.data ?? []);
  const [cursor, setCursor] = useState<string | null>(
    initial?.next_cursor ?? null,
  );
  const [q, setQ] = useState('');
  // Busca já aplicada: o "carregar mais" continua a consulta que gerou o cursor.
  const [applied, setApplied] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('clients.loadError'),
  );
  const [notice, setNotice] = useState<string | null>(null);
  const [searched, setSearched] = useState(false);

  const [dialog, setDialog] = useState<Dialogs>(null);
  const [form, setForm] = useState<Form>(EMPTY);
  const [saving, setSaving] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const role = me.user.role;
  const canWrite = me.can_write && !me.impersonating;
  const manager = role === 'owner' || role === 'manager';

  async function load(append: boolean) {
    const search = append ? applied : q.trim();
    const query = new URLSearchParams({ limit: String(LIMIT) });

    if (search) query.set('q', search);
    if (append && cursor) query.set('cursor', cursor);

    setBusy(true);
    setError(null);

    try {
      const { data } = await call<ClientPage>('GET', `clients?${query}`);
      setApplied(search);
      setSearched(Boolean(search));
      setRows((current) => (append ? [...current, ...data.data] : data.data));
      setCursor(data.next_cursor);
    } catch (failure) {
      setError(
        failure instanceof ApiError && failure.fieldErrors.q
          ? failure.fieldErrors.q
          : failure instanceof ApiError
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

  function open(next: Dialogs) {
    setDialogError(null);
    setFieldErrors({});

    if (next?.kind === 'form') {
      const client = next.client;

      if (client) {
        const { country, national } = splitE164(client.phone);
        setForm({
          name: client.name,
          country,
          national: formatNational(country, national),
          email: client.email ?? '',
          instagram: client.instagram ?? '',
          notes: client.notes ?? '',
        });
      } else {
        setForm(EMPTY);
      }
    }

    setDialog(next);
  }

  function explain(failure: unknown): string {
    if (failure instanceof ApiError && failure.status === 403) {
      return failure.code === 'impersonation_forbidden'
        ? t('clients.supportReadOnly')
        : t('panel.noPermission');
    }

    return failure instanceof ApiError
      ? failure.message
      : t('common.genericError');
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    if (dialog?.kind !== 'form') return;

    const client = dialog.client;
    const body = {
      name: form.name.trim(),
      phone: toE164(form.country, form.national),
      email: form.email.trim() === '' ? null : form.email.trim(),
      instagram: form.instagram.trim() === '' ? null : form.instagram.trim(),
      notes: form.notes.trim() === '' ? null : form.notes.trim(),
    };

    setSaving(true);
    setDialogError(null);
    setFieldErrors({});

    try {
      const { data } = client
        ? await call<Client>('PATCH', `clients/${client.id}`, { body })
        : await call<Client>('POST', 'clients', { body });

      setRows((current) =>
        client
          ? current.map((row) => (row.id === data.id ? data : row))
          : [data, ...current],
      );
      setDialog(null);
      setNotice(t(client ? 'clients.saved' : 'clients.created'));
    } catch (failure) {
      if (
        failure instanceof ApiError &&
        Object.keys(failure.fieldErrors).length > 0
      ) {
        setFieldErrors(failure.fieldErrors);
      } else {
        setDialogError(explain(failure));
      }
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (dialog?.kind !== 'delete') return;

    const target = dialog.client;

    setSaving(true);
    setDialogError(null);

    try {
      await call('DELETE', `clients/${target.id}`);
      setRows((current) => current.filter((row) => row.id !== target.id));
      setDialog(null);
      setNotice(t('clients.deleted'));
    } catch (failure) {
      setDialogError(explain(failure));
    } finally {
      setSaving(false);
    }
  }

  const dialogClient = dialog && dialog.kind !== 'form' ? dialog.client : null;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('clients.title')}
        description={t('clients.text')}
        actions={
          <Button
            disabled={!canWrite}
            onClick={() => open({ kind: 'form', client: null })}
          >
            {t('clients.new')}
          </Button>
        }
      />
      {notice ? <Alert tone="success">{notice}</Alert> : null}
      {me.impersonating ? (
        <Alert tone="info">{t('clients.supportReadOnly')}</Alert>
      ) : !me.can_write ? (
        <Alert tone="warning">{t('clients.readOnly')}</Alert>
      ) : null}

      <form
        onSubmit={search}
        role="search"
        aria-label={t('clients.searchLabel')}
        className="grid gap-4 sm:grid-cols-[1fr_auto] sm:items-end"
      >
        <TextField
          label={t('clients.search')}
          hint={t('clients.searchHint')}
          value={q}
          onChange={(event) => setQ(event.target.value)}
          maxLength={100}
          autoComplete="off"
        />
        <Button type="submit" loading={busy && rows.length === 0}>
          {t('clients.searchSubmit')}
        </Button>
      </form>

      {error ? <Alert tone="danger">{error}</Alert> : null}

      {rows.length === 0 && !error ? (
        <EmptyState
          title={t(searched ? 'clients.empty' : 'clients.emptyFirst')}
        />
      ) : (
        <Table caption={t('clients.caption')}>
          <thead>
            <Tr>
              <Th>{t('clients.name')}</Th>
              <Th>{t('clients.phone')}</Th>
              <Th>{t('clients.contact')}</Th>
              <Th>{t('clients.actions')}</Th>
            </Tr>
          </thead>
          <tbody>
            {rows.map((client) => (
              <Tr key={client.id}>
                <Td>{client.name}</Td>
                <Td>{displayPhone(client.phone)}</Td>
                <Td>
                  {[client.email, client.instagram && `@${client.instagram}`]
                    .filter(Boolean)
                    .join(' · ') || '—'}
                </Td>
                <Td>
                  <span className="flex flex-wrap gap-2">
                    <Button
                      variant="secondary"
                      disabled={!canWrite}
                      onClick={() => open({ kind: 'form', client })}
                      aria-label={`${t('clients.edit')}: ${client.name}`}
                    >
                      {t('clients.edit')}
                    </Button>
                    {manager ? (
                      <>
                        <Button
                          variant="secondary"
                          onClick={() => open({ kind: 'history', client })}
                          aria-label={`${t('clients.history')}: ${client.name}`}
                        >
                          {t('clients.history')}
                        </Button>
                        <Button
                          variant="secondary"
                          disabled={!canWrite}
                          onClick={() => open({ kind: 'delete', client })}
                          aria-label={`${t('clients.delete')}: ${client.name}`}
                        >
                          {t('clients.delete')}
                        </Button>
                      </>
                    ) : null}
                  </span>
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
          {t('clients.loadMore')}
        </Button>
      ) : null}

      <Dialog
        open={dialog?.kind === 'form'}
        onClose={() => setDialog(null)}
        title={t(
          dialog?.kind === 'form' && dialog.client
            ? 'clients.editTitle'
            : 'clients.createTitle',
        )}
      >
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
          <TextField
            label={t('clients.name')}
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            error={fieldErrors.name}
            maxLength={100}
            autoComplete="off"
            required
          />
          <div className="grid gap-4 sm:grid-cols-[11rem_1fr]">
            <SelectField
              label={t('clients.country')}
              value={form.country.code}
              onChange={(event) => {
                const country =
                  COUNTRIES.find((item) => item.code === event.target.value) ??
                  DEFAULT_COUNTRY;
                setForm({
                  ...form,
                  country,
                  national: formatNational(country, form.national),
                });
              }}
            >
              {COUNTRIES.map((country) => (
                <option key={country.code} value={country.code}>
                  {`${country.name} (+${country.ddi})`}
                </option>
              ))}
            </SelectField>
            <TextField
              label={t('clients.phone')}
              hint={t('clients.phoneHint')}
              type="tel"
              inputMode="tel"
              value={form.national}
              onChange={(event) =>
                setForm({
                  ...form,
                  national: formatNational(form.country, event.target.value),
                })
              }
              error={fieldErrors.phone}
              autoComplete="off"
              required
            />
          </div>
          <TextField
            label={t('clients.email')}
            type="email"
            value={form.email}
            onChange={(event) =>
              setForm({ ...form, email: event.target.value })
            }
            error={fieldErrors.email}
            autoComplete="off"
          />
          <TextField
            label={t('clients.instagram')}
            hint={t('clients.instagramHint')}
            value={form.instagram}
            onChange={(event) =>
              setForm({ ...form, instagram: event.target.value })
            }
            error={fieldErrors.instagram}
            autoComplete="off"
          />
          <TextareaField
            label={t('clients.notes')}
            hint={t('clients.notesHint')}
            value={form.notes}
            onChange={(event) =>
              setForm({ ...form, notes: event.target.value })
            }
            error={fieldErrors.notes}
            maxLength={2000}
          />
          <div className="flex flex-wrap justify-end gap-2">
            <Button
              variant="secondary"
              onClick={() => setDialog(null)}
              disabled={saving}
            >
              {t('common.cancel')}
            </Button>
            <Button type="submit" loading={saving}>
              {t('clients.save')}
            </Button>
          </div>
        </form>
      </Dialog>

      <Dialog
        open={dialog?.kind === 'delete'}
        onClose={() => setDialog(null)}
        title={t('clients.deleteTitle')}
        description={
          dialogClient
            ? tf('clients.deleteText', { name: dialogClient.name })
            : undefined
        }
      >
        <div className="flex flex-col gap-4">
          {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
          <div className="flex flex-wrap justify-end gap-2">
            <Button
              variant="secondary"
              onClick={() => setDialog(null)}
              disabled={saving}
            >
              {t('common.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={saving}
              onClick={() => void remove()}
            >
              {t('clients.deleteConfirm')}
            </Button>
          </div>
        </div>
      </Dialog>

      <Dialog
        open={dialog?.kind === 'history'}
        onClose={() => setDialog(null)}
        title={
          dialogClient
            ? tf('clients.historyTitle', { name: dialogClient.name })
            : ''
        }
      >
        {dialog?.kind === 'history' ? (
          <EntityHistory entityType="client" entityId={dialog.client.id} />
        ) : null}
      </Dialog>
    </div>
  );
}
