'use client';

import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import {
  formatBusinessDate,
  fromLocalInput,
  toLocalInput,
  type Professional,
  type TimeOff,
  type TimeOffPage,
} from '@/lib/professional-types';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';

const LIMIT = 20;

type Form = {
  professional: string;
  startsAt: string;
  endsAt: string;
  reason: string;
};

type Dialogs =
  | { kind: 'form'; off: TimeOff | null }
  | { kind: 'delete'; off: TimeOff }
  | null;

function range(off: TimeOff): string {
  const start = toLocalInput(off.starts_at);
  const end = toLocalInput(off.ends_at);

  return `${formatBusinessDate(off.starts_at)} ${start.slice(11)} – ${formatBusinessDate(off.ends_at)} ${end.slice(11)}`;
}

/** Folgas do negócio: lista das próximas, filtro por pessoa, cadastro, edição e exclusão. */
export function TimeOffsSection({
  initial,
  professionals,
  removedIds,
  canWrite,
  manager,
  onNotice,
}: {
  initial: TimeOffPage | null;
  professionals: Professional[];
  /** Profissionais excluídos nesta tela: as folgas deles saíram junto. */
  removedIds: string[];
  canWrite: boolean;
  manager: boolean;
  onNotice: (notice: { tone: 'success' | 'danger'; text: string }) => void;
}) {
  const [rows, setRows] = useState<TimeOff[]>(initial?.data ?? []);
  const [cursor, setCursor] = useState<string | null>(
    initial?.next_cursor ?? null,
  );
  const [filter, setFilter] = useState('');
  const [applied, setApplied] = useState('');
  const [busy, setBusy] = useState(false);
  const [more, setMore] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('professionals.timeOffLoadError'),
  );
  const [dialog, setDialog] = useState<Dialogs>(null);
  const [form, setForm] = useState<Form>({
    professional: '',
    startsAt: '',
    endsAt: '',
    reason: '',
  });
  const [saving, setSaving] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const shown = rows.filter(
    (row) => !row.professional_id || !removedIds.includes(row.professional_id),
  );
  const who = (id: string | null) =>
    id === null
      ? t('professionals.timeOffAll')
      : (professionals.find((item) => item.id === id)?.name ?? '—');

  async function load(append: boolean, person: string) {
    const query = new URLSearchParams({
      limit: String(LIMIT),
      from: new Date().toISOString(),
    });

    if (person) query.set('professional_id', person);
    if (append && cursor) query.set('cursor', cursor);

    setBusy(true);
    setMore(append);
    setError(null);

    try {
      const { data } = await call<TimeOffPage>('GET', `time-offs?${query}`);
      setApplied(person);
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

  function open(next: Dialogs) {
    setDialogError(null);
    setFieldErrors({});

    if (next?.kind === 'form') {
      const off = next.off;

      setForm(
        off
          ? {
              professional: off.professional_id ?? '',
              startsAt: toLocalInput(off.starts_at),
              endsAt: toLocalInput(off.ends_at),
              reason: off.reason ?? '',
            }
          : { professional: '', startsAt: '', endsAt: '', reason: '' },
      );
    }

    setDialog(next);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    if (dialog?.kind !== 'form') return;

    const off = dialog.off;

    if (!form.startsAt || !form.endsAt || form.endsAt <= form.startsAt) {
      setFieldErrors({ ends_at: t('professionals.timeOffRange') });

      return;
    }

    const body = {
      professional_id: form.professional === '' ? null : form.professional,
      starts_at: fromLocalInput(form.startsAt),
      ends_at: fromLocalInput(form.endsAt),
      reason: form.reason.trim() === '' ? null : form.reason.trim(),
    };

    setSaving(true);
    setDialogError(null);
    setFieldErrors({});

    try {
      const { data } = off
        ? await call<TimeOff>('PATCH', `time-offs/${off.id}`, { body })
        : await call<TimeOff>('POST', 'time-offs', { body });

      setRows((current) =>
        [
          ...(off
            ? current.map((row) => (row.id === data.id ? data : row))
            : [data, ...current]),
        ].sort((a, b) => a.starts_at.localeCompare(b.starts_at)),
      );
      setDialog(null);
      onNotice({
        tone: 'success',
        text: t(
          off ? 'professionals.timeOffSaved' : 'professionals.timeOffCreated',
        ),
      });
    } catch (failure) {
      if (
        failure instanceof ApiError &&
        Object.keys(failure.fieldErrors).length > 0
      ) {
        setFieldErrors(failure.fieldErrors);
      } else {
        setDialogError(
          failure instanceof ApiError && failure.status === 403
            ? t('panel.noPermission')
            : failure instanceof ApiError
              ? failure.message
              : t('common.genericError'),
        );
      }
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    if (dialog?.kind !== 'delete') return;

    const target = dialog.off;

    setSaving(true);
    setDialogError(null);

    try {
      await call('DELETE', `time-offs/${target.id}`);
      setRows((current) => current.filter((row) => row.id !== target.id));
      setDialog(null);
      onNotice({ tone: 'success', text: t('professionals.timeOffDeleted') });
    } catch (failure) {
      setDialogError(
        failure instanceof ApiError
          ? failure.message
          : t('common.genericError'),
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <section aria-labelledby="folgas" className="space-y-4">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="folgas" className="text-lg font-semibold text-ink">
            {t('professionals.timeOffs')}
          </h2>
          <p className="text-sm text-muted">
            {t('professionals.timeOffsText')}
          </p>
        </div>
        {manager ? (
          <Button
            disabled={!canWrite}
            onClick={() => open({ kind: 'form', off: null })}
          >
            {t('professionals.timeOffNew')}
          </Button>
        ) : null}
      </div>

      <SelectField
        className="max-w-xs"
        label={t('professionals.filterPerson')}
        value={filter}
        onChange={(event) => {
          setFilter(event.target.value);
          void load(false, event.target.value);
        }}
      >
        <option value="">{t('professionals.filterAll')}</option>
        {professionals.map((professional) => (
          <option key={professional.id} value={professional.id}>
            {professional.name}
          </option>
        ))}
      </SelectField>

      {error ? <Alert tone="danger">{error}</Alert> : null}
      {shown.length === 0 && !error ? (
        <EmptyState title={t('professionals.timeOffEmpty')} />
      ) : (
        <Table caption={t('professionals.timeOffsCaption')}>
          <thead>
            <Tr>
              <Th>{t('professionals.timeOffFor')}</Th>
              <Th>{t('professionals.period')}</Th>
              <Th>{t('professionals.reason')}</Th>
              {manager ? <Th>{t('professionals.actions')}</Th> : null}
            </Tr>
          </thead>
          <tbody>
            {shown.map((off) => (
              <Tr key={off.id}>
                <Td>{who(off.professional_id)}</Td>
                <Td>{range(off)}</Td>
                <Td>{off.reason ?? '—'}</Td>
                {manager ? (
                  <Td>
                    <span className="flex flex-wrap gap-2">
                      <Button
                        variant="secondary"
                        disabled={!canWrite}
                        onClick={() => open({ kind: 'form', off })}
                        aria-label={`${t('professionals.edit')}: ${who(off.professional_id)} ${formatBusinessDate(off.starts_at)}`}
                      >
                        {t('professionals.edit')}
                      </Button>
                      <Button
                        variant="secondary"
                        disabled={!canWrite}
                        onClick={() => open({ kind: 'delete', off })}
                        aria-label={`${t('professionals.delete')}: ${who(off.professional_id)} ${formatBusinessDate(off.starts_at)}`}
                      >
                        {t('professionals.delete')}
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
          onClick={() => void load(true, applied)}
        >
          {t('professionals.loadMore')}
        </Button>
      ) : null}

      <Dialog
        open={dialog?.kind === 'form'}
        onClose={() => setDialog(null)}
        title={t(
          dialog?.kind === 'form' && dialog.off
            ? 'professionals.timeOffEditTitle'
            : 'professionals.timeOffTitle',
        )}
      >
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
          <SelectField
            label={t('professionals.timeOffFor')}
            value={form.professional}
            onChange={(event) =>
              setForm({ ...form, professional: event.target.value })
            }
            error={fieldErrors.professional_id}
          >
            <option value="">{t('professionals.timeOffAll')}</option>
            {professionals.map((professional) => (
              <option key={professional.id} value={professional.id}>
                {professional.name}
              </option>
            ))}
          </SelectField>
          <TextField
            label={t('professionals.startsAt')}
            type="datetime-local"
            value={form.startsAt}
            onChange={(event) =>
              setForm({ ...form, startsAt: event.target.value })
            }
            error={fieldErrors.starts_at}
            required
          />
          <TextField
            label={t('professionals.endsAt')}
            type="datetime-local"
            value={form.endsAt}
            onChange={(event) =>
              setForm({ ...form, endsAt: event.target.value })
            }
            error={fieldErrors.ends_at}
            required
          />
          <TextField
            label={t('professionals.reason')}
            hint={t('professionals.reasonHint')}
            value={form.reason}
            onChange={(event) =>
              setForm({ ...form, reason: event.target.value })
            }
            error={fieldErrors.reason}
            maxLength={100}
            autoComplete="off"
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
              {t('professionals.save')}
            </Button>
          </div>
        </form>
      </Dialog>

      <Dialog
        open={dialog?.kind === 'delete'}
        onClose={() => setDialog(null)}
        title={t('professionals.timeOffDeleteTitle')}
        description={t('professionals.timeOffDeleteText')}
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
              {t('professionals.deleteConfirm')}
            </Button>
          </div>
        </div>
      </Dialog>
    </section>
  );
}
