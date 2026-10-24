'use client';

import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { Me } from '@/lib/panel-types';
import type {
  Professional,
  ProfessionalPage,
  TimeOffPage,
  WorkingRange,
} from '@/lib/professional-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { CheckboxField, SelectField, TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { TimeOffsSection } from './time-offs-section';
import { WorkingHoursDialog } from './working-hours-dialog';

type Form = {
  name: string;
  color: string;
  commission: string;
  user: string;
};

const EMPTY: Form = { name: '', color: '#2563EB', commission: '0', user: '' };

type Dialogs =
  | { kind: 'form'; professional: Professional | null }
  | { kind: 'delete'; professional: Professional }
  | null;

function sortByName(list: Professional[]): Professional[] {
  return [...list].sort((a, b) =>
    a.name.localeCompare(b.name, 'pt-BR', { sensitivity: 'base' }),
  );
}

/** Profissionais do negócio, expediente semanal e folgas. Todos os papéis consultam; dona e gerente alteram. */
export function ProfessionalsView({
  me,
  initial,
  timeOffs,
  team,
}: {
  me: Me;
  initial: ProfessionalPage | null;
  timeOffs: TimeOffPage | null;
  /** Pessoas ativas da equipe que podem ser ligadas a um profissional (só dona e gerente recebem a lista). */
  team: Array<{ id: string; name: string }>;
}) {
  const [rows, setRows] = useState<Professional[]>(initial?.data ?? []);
  const [cursor, setCursor] = useState<string | null>(
    initial?.next_cursor ?? null,
  );
  const [loadingMore, setLoadingMore] = useState(false);
  const [removedIds, setRemovedIds] = useState<string[]>([]);
  const [showInactive, setShowInactive] = useState(false);
  const error = initial ? null : t('professionals.loadError');
  const [notice, setNotice] = useState<{
    tone: 'success' | 'danger';
    text: string;
  } | null>(null);
  const [busy, setBusy] = useState(false);

  const [dialog, setDialog] = useState<Dialogs>(null);
  const [hours, setHours] = useState<{
    professional: Professional;
    ranges: WorkingRange[];
  } | null>(null);
  const [form, setForm] = useState<Form>(EMPTY);
  const [saving, setSaving] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const manager = me.user.role === 'owner' || me.user.role === 'manager';
  const canWrite = manager && me.can_write && !me.impersonating;
  const visible = showInactive
    ? rows
    : rows.filter((row) => row.status === 'active');

  function explain(failure: unknown): string {
    if (failure instanceof ApiError && failure.status === 403) {
      return failure.code === 'impersonation_forbidden'
        ? t('professionals.supportReadOnly')
        : t('panel.noPermission');
    }

    return failure instanceof ApiError
      ? failure.message
      : t('common.genericError');
  }

  function open(next: Dialogs) {
    setDialogError(null);
    setFieldErrors({});

    if (next?.kind === 'form') {
      const professional = next.professional;

      setForm(
        professional
          ? {
              name: professional.name,
              color: professional.color,
              commission: String(professional.commission_percent ?? 0),
              user: professional.user_id ?? '',
            }
          : EMPTY,
      );
    }

    setDialog(next);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    if (dialog?.kind !== 'form') return;

    const professional = dialog.professional;
    const body = {
      name: form.name.trim(),
      color: form.color,
      commission_percent: Number(form.commission),
      user_id: form.user === '' ? null : form.user,
    };

    setSaving(true);
    setDialogError(null);
    setFieldErrors({});

    try {
      const { data } = professional
        ? await call<Professional>(
            'PATCH',
            `professionals/${professional.id}`,
            { body },
          )
        : await call<Professional>('POST', 'professionals', { body });

      setRows((current) =>
        sortByName(
          professional
            ? current.map((row) => (row.id === data.id ? data : row))
            : [...current, data],
        ),
      );
      setDialog(null);
      setNotice({
        tone: 'success',
        text: t(professional ? 'professionals.saved' : 'professionals.created'),
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

  async function toggle(professional: Professional) {
    const next = professional.status === 'active' ? 'inactive' : 'active';

    setBusy(true);

    try {
      const { data } = await call<Professional>(
        'PATCH',
        `professionals/${professional.id}`,
        { body: { status: next } },
      );
      setRows((current) =>
        current.map((row) => (row.id === data.id ? data : row)),
      );
      setNotice({
        tone: 'success',
        text: t(
          next === 'inactive'
            ? 'professionals.deactivated'
            : 'professionals.reactivated',
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

    const target = dialog.professional;

    setSaving(true);
    setDialogError(null);

    try {
      await call('DELETE', `professionals/${target.id}`);
      setRows((current) => current.filter((row) => row.id !== target.id));
      setRemovedIds((current) => [...current, target.id]);
      setDialog(null);
      setNotice({ tone: 'success', text: t('professionals.deleted') });
    } catch (failure) {
      setDialogError(explain(failure));
    } finally {
      setSaving(false);
    }
  }

  async function loadMore() {
    if (!cursor) return;

    setLoadingMore(true);

    try {
      const { data } = await call<ProfessionalPage>(
        'GET',
        `professionals?limit=50&status=all&cursor=${encodeURIComponent(cursor)}`,
      );
      setRows((current) => sortByName([...current, ...data.data]));
      setCursor(data.next_cursor);
    } catch (failure) {
      setNotice({ tone: 'danger', text: explain(failure) });
    } finally {
      setLoadingMore(false);
    }
  }

  async function openHours(professional: Professional) {
    setBusy(true);

    try {
      const { data } = await call<{ data: WorkingRange[] }>(
        'GET',
        `professionals/${professional.id}/working-hours`,
      );
      setHours({ professional, ranges: data.data });
    } catch (failure) {
      setNotice({ tone: 'danger', text: explain(failure) });
    } finally {
      setBusy(false);
    }
  }

  const deleting = dialog?.kind === 'delete' ? dialog.professional : null;

  return (
    <div className="space-y-8">
      <div className="space-y-6">
        <PageHeader
          title={t('professionals.title')}
          description={t('professionals.text')}
          actions={
            manager ? (
              <Button
                disabled={!canWrite}
                onClick={() => open({ kind: 'form', professional: null })}
              >
                {t('professionals.new')}
              </Button>
            ) : null
          }
        />
        {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}
        {me.impersonating ? (
          <Alert tone="info">{t('professionals.supportReadOnly')}</Alert>
        ) : !me.can_write ? (
          <Alert tone="warning">{t('professionals.readOnly')}</Alert>
        ) : !manager ? (
          <Alert tone="info">{t('professionals.viewOnly')}</Alert>
        ) : null}
        {error ? <Alert tone="danger">{error}</Alert> : null}

        <CheckboxField
          label={t('professionals.showInactive')}
          checked={showInactive}
          onChange={(event) => setShowInactive(event.target.checked)}
        />

        {visible.length === 0 && !error ? (
          <EmptyState
            title={t(
              rows.length === 0
                ? 'professionals.emptyFirst'
                : 'professionals.empty',
            )}
          />
        ) : (
          <Table caption={t('professionals.caption')}>
            <thead>
              <Tr>
                <Th>{t('professionals.name')}</Th>
                <Th>{t('professionals.commission')}</Th>
                <Th>{t('professionals.access')}</Th>
                <Th>{t('professionals.status')}</Th>
                <Th>{t('professionals.actions')}</Th>
              </Tr>
            </thead>
            <tbody>
              {visible.map((professional) => (
                <Tr key={professional.id}>
                  <Td>
                    <span className="flex items-center gap-2">
                      <span
                        aria-hidden="true"
                        className="inline-block size-3 rounded-full border border-control-border"
                        style={{ backgroundColor: professional.color }}
                      />
                      {professional.name}
                    </span>
                  </Td>
                  <Td>
                    {professional.commission_percent === null
                      ? '—'
                      : `${professional.commission_percent}%`}
                  </Td>
                  <Td>
                    {t(
                      professional.user_id
                        ? 'professionals.hasAccess'
                        : 'professionals.noAccess',
                    )}
                  </Td>
                  <Td>
                    <Badge
                      tone={
                        professional.status === 'active' ? 'success' : 'neutral'
                      }
                    >
                      {t(
                        professional.status === 'active'
                          ? 'professionals.active'
                          : 'professionals.inactive',
                      )}
                    </Badge>
                  </Td>
                  <Td>
                    <span className="flex flex-wrap gap-2">
                      <Button
                        variant="secondary"
                        disabled={busy}
                        onClick={() => void openHours(professional)}
                        aria-label={`${t(manager ? 'professionals.hours' : 'professionals.viewHours')}: ${professional.name}`}
                      >
                        {t(
                          manager
                            ? 'professionals.hours'
                            : 'professionals.viewHours',
                        )}
                      </Button>
                      {manager ? (
                        <>
                          <Button
                            variant="secondary"
                            disabled={!canWrite}
                            onClick={() => open({ kind: 'form', professional })}
                            aria-label={`${t('professionals.edit')}: ${professional.name}`}
                          >
                            {t('professionals.edit')}
                          </Button>
                          <Button
                            variant="secondary"
                            disabled={!canWrite || busy}
                            onClick={() => void toggle(professional)}
                            aria-label={`${t(professional.status === 'active' ? 'professionals.deactivate' : 'professionals.reactivate')}: ${professional.name}`}
                          >
                            {t(
                              professional.status === 'active'
                                ? 'professionals.deactivate'
                                : 'professionals.reactivate',
                            )}
                          </Button>
                          <Button
                            variant="secondary"
                            disabled={!canWrite}
                            onClick={() =>
                              open({ kind: 'delete', professional })
                            }
                            aria-label={`${t('professionals.delete')}: ${professional.name}`}
                          >
                            {t('professionals.delete')}
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
            loading={loadingMore}
            onClick={() => void loadMore()}
          >
            {t('professionals.loadMore')}
          </Button>
        ) : null}
      </div>

      <TimeOffsSection
        initial={timeOffs}
        professionals={rows}
        removedIds={removedIds}
        canWrite={canWrite}
        manager={manager}
        onNotice={setNotice}
      />

      <Dialog
        open={dialog?.kind === 'form'}
        onClose={() => setDialog(null)}
        title={t(
          dialog?.kind === 'form' && dialog.professional
            ? 'professionals.editTitle'
            : 'professionals.createTitle',
        )}
      >
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
          <TextField
            label={t('professionals.name')}
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            error={fieldErrors.name}
            maxLength={100}
            autoComplete="off"
            required
          />
          <TextField
            label={t('professionals.color')}
            hint={t('professionals.colorHint')}
            type="color"
            value={form.color}
            onChange={(event) =>
              setForm({ ...form, color: event.target.value.toUpperCase() })
            }
            error={fieldErrors.color}
          />
          <TextField
            label={t('professionals.commission')}
            hint={t('professionals.commissionHint')}
            type="number"
            inputMode="numeric"
            min={0}
            max={100}
            value={form.commission}
            onChange={(event) =>
              setForm({ ...form, commission: event.target.value })
            }
            error={fieldErrors.commission_percent}
          />
          <SelectField
            label={t('professionals.access')}
            hint={t('professionals.accessHint')}
            value={form.user}
            onChange={(event) => setForm({ ...form, user: event.target.value })}
            error={fieldErrors.user_id}
          >
            <option value="">{t('professionals.accessNone')}</option>
            {team.map((person) => (
              <option key={person.id} value={person.id}>
                {person.name}
              </option>
            ))}
          </SelectField>
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
        title={t('professionals.deleteTitle')}
        description={
          deleting
            ? tf('professionals.deleteText', { name: deleting.name })
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
              {t('professionals.deleteConfirm')}
            </Button>
          </div>
        </div>
      </Dialog>

      <WorkingHoursDialog
        key={hours?.professional.id ?? 'none'}
        professional={hours?.professional ?? null}
        initial={hours?.ranges ?? []}
        canWrite={canWrite}
        onClose={() => setHours(null)}
        onSaved={(text) => {
          setHours(null);
          setNotice({ tone: 'success', text });
        }}
      />
    </div>
  );
}
