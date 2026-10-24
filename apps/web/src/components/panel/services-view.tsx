'use client';

import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { formatBRL } from '@/lib/format';
import { centsToReais, reaisToCents } from '@/lib/money';
import type { Me } from '@/lib/panel-types';
import {
  formatDuration,
  type PresetResult,
  type Service,
  type ServiceCategory,
  type ServicePage,
} from '@/lib/service-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import {
  CheckboxField,
  SelectField,
  TextField,
  TextareaField,
} from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { ServiceCategoriesDialog } from './service-categories-dialog';

const LIMIT = 20;

type Form = {
  name: string;
  description: string;
  category: string;
  duration: string;
  price: string;
  onRequest: boolean;
  before: string;
  after: string;
  deposit: boolean;
};

const EMPTY: Form = {
  name: '',
  description: '',
  category: '',
  duration: '60',
  price: '',
  onRequest: true,
  before: '0',
  after: '0',
  deposit: false,
};

type Dialogs =
  | { kind: 'form'; service: Service | null }
  | { kind: 'delete'; service: Service }
  | null;

function sortByName(list: Service[]): Service[] {
  return [...list].sort((a, b) =>
    a.name.localeCompare(b.name, 'pt-BR', { sensitivity: 'base' }),
  );
}

/** Catálogo de serviços: todos os papéis consultam; dona e gerente cadastram, editam, arquivam, excluem e gerem categorias. */
export function ServicesView({
  me,
  initial,
  categories: initialCategories,
}: {
  me: Me;
  initial: ServicePage | null;
  categories: ServiceCategory[];
}) {
  const [rows, setRows] = useState<Service[]>(initial?.data ?? []);
  const [cursor, setCursor] = useState<string | null>(
    initial?.next_cursor ?? null,
  );
  const [categories, setCategories] = useState(initialCategories);
  const [q, setQ] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [archived, setArchived] = useState(false);
  // Filtros já aplicados: o "carregar mais" continua a consulta que gerou o cursor.
  const [applied, setApplied] = useState({
    q: '',
    category: '',
    archived: false,
  });
  const [busy, setBusy] = useState(false);
  const [more, setMore] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('services.loadError'),
  );
  const [notice, setNotice] = useState<{
    tone: 'success' | 'danger';
    text: string;
  } | null>(null);
  const [filtered, setFiltered] = useState(false);

  const [dialog, setDialog] = useState<Dialogs>(null);
  const [categoriesOpen, setCategoriesOpen] = useState(false);
  const [form, setForm] = useState<Form>(EMPTY);
  const [saving, setSaving] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const manager = me.user.role === 'owner' || me.user.role === 'manager';
  const canWrite = manager && me.can_write && !me.impersonating;
  const categoryName = (id: string | null) =>
    categories.find((item) => item.id === id)?.name ?? t('services.noCategory');

  async function load(append: boolean) {
    const filters = append
      ? applied
      : { q: q.trim(), category: categoryFilter, archived };
    const query = new URLSearchParams({
      limit: String(LIMIT),
      status: filters.archived ? 'all' : 'active',
    });

    if (filters.q) query.set('q', filters.q);
    if (filters.category) query.set('category_id', filters.category);
    if (append && cursor) query.set('cursor', cursor);

    setBusy(true);
    setMore(append);
    setError(null);

    try {
      const { data } = await call<ServicePage>('GET', `services?${query}`);
      setApplied(filters);
      setFiltered(Boolean(filters.q || filters.category));
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

    if (q.trim().length === 1) {
      setError(t('services.searchHint'));

      return;
    }

    void load(false);
  }

  function open(next: Dialogs) {
    setDialogError(null);
    setFieldErrors({});

    if (next?.kind === 'form') {
      const service = next.service;

      setForm(
        service
          ? {
              name: service.name,
              description: service.description ?? '',
              category: service.category_id ?? '',
              duration: String(service.duration_minutes),
              price: centsToReais(service.price_cents),
              onRequest: service.price_cents === null,
              before: String(service.buffer_before_minutes),
              after: String(service.buffer_after_minutes),
              deposit: service.requires_deposit,
            }
          : EMPTY,
      );
    }

    setDialog(next);
  }

  function explain(failure: unknown): string {
    if (failure instanceof ApiError && failure.status === 403) {
      return failure.code === 'impersonation_forbidden'
        ? t('services.supportReadOnly')
        : t('panel.noPermission');
    }

    return failure instanceof ApiError
      ? failure.message
      : t('common.genericError');
  }

  /** Recarrega as categorias (a contagem de serviços de cada uma muda quando o catálogo muda). */
  async function refreshCategories() {
    try {
      const { data } = await call<{ data: ServiceCategory[] }>(
        'GET',
        'service-categories',
      );

      if (Array.isArray(data?.data)) setCategories(data.data);
    } catch {
      // A lista de categorias volta no próximo carregamento; o aviso principal já foi dado.
    }
  }

  /** Aplica a mudança na lista: o serviço arquivado some da lista padrão (a menos que "mostrar arquivados"). */
  function place(saved: Service, created: boolean) {
    setRows((current) => {
      const next = created
        ? [saved, ...current]
        : current.map((row) => (row.id === saved.id ? saved : row));

      return sortByName(
        applied.archived ? next : next.filter((row) => row.status === 'active'),
      );
    });
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    if (dialog?.kind !== 'form') return;

    const service = dialog.service;
    const cents = form.onRequest ? null : reaisToCents(form.price);

    if (!form.onRequest && (cents === null || Number.isNaN(cents))) {
      setFieldErrors({ price_cents: t('services.priceInvalid') });

      return;
    }

    const body = {
      name: form.name.trim(),
      description:
        form.description.trim() === '' ? null : form.description.trim(),
      category_id: form.category === '' ? null : form.category,
      duration_minutes: Number(form.duration),
      price_cents: cents,
      buffer_before_minutes: Number(form.before),
      buffer_after_minutes: Number(form.after),
      requires_deposit: form.deposit,
    };

    setSaving(true);
    setDialogError(null);
    setFieldErrors({});

    try {
      const { data } = service
        ? await call<Service>('PATCH', `services/${service.id}`, { body })
        : await call<Service>('POST', 'services', { body });

      place(data, !service);
      void refreshCategories();
      setDialog(null);
      setNotice({
        tone: 'success',
        text: t(service ? 'services.saved' : 'services.created'),
      });
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

  async function toggleArchive(service: Service) {
    const next = service.status === 'active' ? 'archived' : 'active';

    setBusy(true);

    try {
      const { data } = await call<Service>('PATCH', `services/${service.id}`, {
        body: { status: next },
      });
      place(data, false);
      void refreshCategories();
      setNotice({
        tone: 'success',
        text: t(
          next === 'archived'
            ? 'services.archivedDone'
            : 'services.unarchivedDone',
        ),
      });
    } catch (failure) {
      setNotice({ tone: 'danger', text: explain(failure) });
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (dialog?.kind !== 'delete') return;

    const target = dialog.service;

    setSaving(true);
    setDialogError(null);

    try {
      await call('DELETE', `services/${target.id}`);
      setRows((current) => current.filter((row) => row.id !== target.id));
      void refreshCategories();
      setDialog(null);
      setNotice({ tone: 'success', text: t('services.deleted') });
    } catch (failure) {
      setDialogError(explain(failure));
    } finally {
      setSaving(false);
    }
  }

  async function applyPreset() {
    setBusy(true);

    try {
      const { data } = await call<PresetResult>('POST', 'services/presets');
      const created = data.created.services;

      setNotice({
        tone: 'success',
        text: tf('services.presetDone', {
          created,
          skipped: data.skipped.services,
        }),
      });

      const [list, cats] = await Promise.all([
        call<ServicePage>('GET', `services?limit=${LIMIT}&status=active`),
        call<{ data: ServiceCategory[] }>('GET', 'service-categories'),
      ]);
      setRows(list.data.data);
      setCursor(list.data.next_cursor);
      setCategories(cats.data.data);
    } catch (failure) {
      setNotice({
        tone: 'danger',
        text:
          failure instanceof ApiError && failure.fieldErrors.niche
            ? t('services.presetNoNiche')
            : explain(failure),
      });
    } finally {
      setBusy(false);
    }
  }

  const dialogService = dialog?.kind === 'delete' ? dialog.service : null;
  const firstUse =
    rows.length === 0 && !error && !filtered && !applied.archived;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('services.title')}
        description={t('services.text')}
        actions={
          <span className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => setCategoriesOpen(true)}>
              {t('services.categories')}
            </Button>
            {manager ? (
              <Button
                disabled={!canWrite}
                onClick={() => open({ kind: 'form', service: null })}
              >
                {t('services.new')}
              </Button>
            ) : null}
          </span>
        }
      />
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}
      {me.impersonating ? (
        <Alert tone="info">{t('services.supportReadOnly')}</Alert>
      ) : !me.can_write ? (
        <Alert tone="warning">{t('services.readOnly')}</Alert>
      ) : !manager ? (
        <Alert tone="info">{t('services.viewOnly')}</Alert>
      ) : null}

      <form
        onSubmit={search}
        role="search"
        aria-label={t('services.searchLabel')}
        className="grid gap-4 sm:grid-cols-[1fr_14rem_auto] sm:items-end"
      >
        <TextField
          label={t('services.search')}
          hint={t('services.searchHint')}
          value={q}
          onChange={(event) => setQ(event.target.value)}
          maxLength={100}
          autoComplete="off"
        />
        <SelectField
          label={t('services.category')}
          value={categoryFilter}
          onChange={(event) => setCategoryFilter(event.target.value)}
        >
          <option value="">{t('services.allCategories')}</option>
          {categories.map((category) => (
            <option key={category.id} value={category.id}>
              {category.name}
            </option>
          ))}
        </SelectField>
        <Button type="submit" loading={busy && !more}>
          {t('services.searchSubmit')}
        </Button>
        <CheckboxField
          className="sm:col-span-3"
          label={t('services.showArchived')}
          checked={archived}
          onChange={(event) => setArchived(event.target.checked)}
        />
      </form>

      {error ? <Alert tone="danger">{error}</Alert> : null}
      {busy && !more ? (
        <p role="status" className="text-muted">
          {t('common.loading')}
        </p>
      ) : null}

      {rows.length === 0 && !error ? (
        <div className="space-y-3">
          <EmptyState
            title={t(firstUse ? 'services.emptyFirst' : 'services.empty')}
          />
          {firstUse && canWrite ? (
            <Button loading={busy} onClick={() => void applyPreset()}>
              {t('services.preset')}
            </Button>
          ) : null}
        </div>
      ) : (
        <Table caption={t('services.caption')}>
          <thead>
            <Tr>
              <Th>{t('services.name')}</Th>
              <Th>{t('services.category')}</Th>
              <Th>{t('services.duration')}</Th>
              <Th>{t('services.price')}</Th>
              <Th>{t('services.deposit')}</Th>
              <Th>{t('services.status')}</Th>
              {manager ? <Th>{t('services.actions')}</Th> : null}
            </Tr>
          </thead>
          <tbody>
            {rows.map((service) => (
              <Tr key={service.id}>
                <Td>{service.name}</Td>
                <Td>{categoryName(service.category_id)}</Td>
                <Td>{formatDuration(service.duration_minutes)}</Td>
                <Td>
                  {service.price_cents === null
                    ? t('services.onRequest')
                    : formatBRL(service.price_cents)}
                </Td>
                <Td>
                  {t(service.requires_deposit ? 'services.yes' : 'services.no')}
                </Td>
                <Td>
                  <Badge
                    tone={service.status === 'active' ? 'success' : 'neutral'}
                  >
                    {t(
                      service.status === 'active'
                        ? 'services.active'
                        : 'services.archived',
                    )}
                  </Badge>
                </Td>
                {manager ? (
                  <Td>
                    <span className="flex flex-wrap gap-2">
                      <Button
                        variant="secondary"
                        disabled={!canWrite}
                        onClick={() => open({ kind: 'form', service })}
                        aria-label={`${t('services.edit')}: ${service.name}`}
                      >
                        {t('services.edit')}
                      </Button>
                      <Button
                        variant="secondary"
                        disabled={!canWrite || busy}
                        onClick={() => void toggleArchive(service)}
                        aria-label={`${t(service.status === 'active' ? 'services.archive' : 'services.unarchive')}: ${service.name}`}
                      >
                        {t(
                          service.status === 'active'
                            ? 'services.archive'
                            : 'services.unarchive',
                        )}
                      </Button>
                      <Button
                        variant="secondary"
                        disabled={!canWrite}
                        onClick={() => open({ kind: 'delete', service })}
                        aria-label={`${t('services.delete')}: ${service.name}`}
                      >
                        {t('services.delete')}
                      </Button>
                    </span>
                  </Td>
                ) : null}
              </Tr>
            ))}
          </tbody>
        </Table>
      )}

      {cursor ? (
        <Button
          variant="secondary"
          loading={busy && more}
          onClick={() => void load(true)}
        >
          {t('services.loadMore')}
        </Button>
      ) : null}

      <Dialog
        open={dialog?.kind === 'form'}
        onClose={() => setDialog(null)}
        title={t(
          dialog?.kind === 'form' && dialog.service
            ? 'services.editTitle'
            : 'services.createTitle',
        )}
      >
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
          <TextField
            label={t('services.name')}
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            error={fieldErrors.name}
            maxLength={100}
            autoComplete="off"
            required
          />
          <SelectField
            label={t('services.category')}
            value={form.category}
            onChange={(event) =>
              setForm({ ...form, category: event.target.value })
            }
            error={fieldErrors.category_id}
          >
            <option value="">{t('services.noCategory')}</option>
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </SelectField>
          <TextareaField
            label={t('services.description')}
            value={form.description}
            onChange={(event) =>
              setForm({ ...form, description: event.target.value })
            }
            error={fieldErrors.description}
            maxLength={500}
          />
          <TextField
            label={t('services.durationMinutes')}
            hint={t('services.durationHint')}
            type="number"
            inputMode="numeric"
            min={5}
            max={720}
            step={5}
            value={form.duration}
            onChange={(event) =>
              setForm({ ...form, duration: event.target.value })
            }
            error={fieldErrors.duration_minutes}
            required
          />
          <CheckboxField
            label={t('services.priceOnRequest')}
            checked={form.onRequest}
            onChange={(event) =>
              setForm({ ...form, onRequest: event.target.checked })
            }
          />
          {form.onRequest ? null : (
            <TextField
              label={t('services.priceReais')}
              hint={t('services.priceHint')}
              inputMode="decimal"
              value={form.price}
              onChange={(event) =>
                setForm({ ...form, price: event.target.value })
              }
              error={fieldErrors.price_cents}
              required
            />
          )}
          <div className="grid gap-4 sm:grid-cols-2">
            <TextField
              label={t('services.bufferBefore')}
              type="number"
              inputMode="numeric"
              min={0}
              max={240}
              step={5}
              value={form.before}
              onChange={(event) =>
                setForm({ ...form, before: event.target.value })
              }
              error={fieldErrors.buffer_before_minutes}
            />
            <TextField
              label={t('services.bufferAfter')}
              hint={t('services.bufferHint')}
              type="number"
              inputMode="numeric"
              min={0}
              max={240}
              step={5}
              value={form.after}
              onChange={(event) =>
                setForm({ ...form, after: event.target.value })
              }
              error={fieldErrors.buffer_after_minutes}
            />
          </div>
          <CheckboxField
            label={t('services.requiresDeposit')}
            checked={form.deposit}
            onChange={(event) =>
              setForm({ ...form, deposit: event.target.checked })
            }
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
              {t('services.save')}
            </Button>
          </div>
        </form>
      </Dialog>

      <Dialog
        open={dialog?.kind === 'delete'}
        onClose={() => setDialog(null)}
        title={t('services.deleteTitle')}
        description={
          dialogService
            ? tf('services.deleteText', { name: dialogService.name })
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
              {t('services.deleteConfirm')}
            </Button>
          </div>
        </div>
      </Dialog>

      <ServiceCategoriesDialog
        open={categoriesOpen}
        onClose={() => setCategoriesOpen(false)}
        categories={categories}
        canWrite={canWrite}
        onChange={(next, text) => {
          setCategories(next);
          setNotice({ tone: 'success', text });
        }}
      />
    </div>
  );
}
