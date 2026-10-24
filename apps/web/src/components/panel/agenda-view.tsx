'use client';

import { useState, type FormEvent } from 'react';
import { t, tf, type MessageKey } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import {
  addDays,
  dayOf,
  dayTitle,
  mondayOf,
  NEXT_STATUS,
  timeOf,
  todayInBusiness,
  weekRange,
  type Appointment,
  type AppointmentList,
  type AppointmentStatus,
  type Slot,
} from '@/lib/appointment-types';
import type { Client, ClientPage } from '@/lib/client-types';
import { formatBRL } from '@/lib/format';
import { centsToReais, reaisToCents } from '@/lib/money';
import type { Me } from '@/lib/panel-types';
import { fromLocalInput, toLocalInput } from '@/lib/professional-types';
import type { Professional } from '@/lib/professional-types';
import type { Service } from '@/lib/service-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge, type Tone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextareaField, TextField } from '@/components/ui/inputs';

const STATUS_TONE: Record<AppointmentStatus, Tone> = {
  pending: 'warning',
  scheduled: 'info',
  completed: 'success',
  canceled: 'neutral',
  no_show: 'danger',
};

type Form = {
  clientId: string;
  professionalId: string;
  serviceId: string;
  startsAt: string;
  endsAt: string;
  value: string;
  description: string;
  notes: string;
  review: string;
  type: 'appointment' | 'retouch';
  session: string;
  sessionsTotal: string;
};

const EMPTY: Form = {
  clientId: '',
  professionalId: '',
  serviceId: '',
  startsAt: '',
  endsAt: '',
  value: '',
  description: '',
  notes: '',
  review: '',
  type: 'appointment',
  session: '',
  sessionsTotal: '',
};

type Dialogs =
  | { kind: 'form'; appointment: Appointment | null; reschedule?: boolean }
  | { kind: 'delete'; appointment: Appointment }
  | null;

/** Agenda da semana (AGD-003): lista por dia, filtro por profissional, agendar, remarcar, mudar a situação e excluir. */
export function AgendaView({
  me,
  initial,
  weekStart,
  professionals,
  services,
}: {
  me: Me;
  initial: AppointmentList | null;
  /** Segunda-feira (`aaaa-mm-dd`) da semana inicial, calculada no servidor. */
  weekStart: string;
  professionals: Professional[];
  services: Service[];
}) {
  const [monday, setMonday] = useState(weekStart);
  const [rows, setRows] = useState<Appointment[]>(initial?.data ?? []);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('agenda.loadError'),
  );
  const [professionalFilter, setProfessionalFilter] = useState('');
  const [notice, setNotice] = useState<{
    tone: 'success' | 'danger';
    text: string;
  } | null>(null);
  const [busy, setBusy] = useState(false);

  const [dialog, setDialog] = useState<Dialogs>(null);
  const [form, setForm] = useState<Form>(EMPTY);
  const [saving, setSaving] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [needsConfirm, setNeedsConfirm] = useState(false);

  const [clientQuery, setClientQuery] = useState('');
  const [clientOptions, setClientOptions] = useState<Client[] | null>(null);
  const [slots, setSlots] = useState<{
    day: string;
    items: Slot[];
  } | null>(null);
  const [slotsError, setSlotsError] = useState<string | null>(null);

  const role = me.user.role;
  const manager = role === 'owner' || role === 'manager';
  const seesValues = role !== 'reception';
  const canWrite = me.can_write && !me.impersonating;
  // O profissional agenda só no próprio perfil (a API também decide): a tela só oferece o dele.
  const usable =
    role === 'professional'
      ? professionals.filter((p) => p.user_id === me.user.id)
      : professionals;
  const range = weekRange(monday);

  function explain(failure: unknown): string {
    if (failure instanceof ApiError && failure.status === 403) {
      return failure.code === 'impersonation_forbidden'
        ? t('agenda.supportReadOnly')
        : t('agenda.noPermission');
    }

    return failure instanceof ApiError
      ? failure.message
      : t('common.genericError');
  }

  function load(nextMonday: string, professionalId: string) {
    const period = weekRange(nextMonday);
    const filter = professionalId
      ? `&professional_id=${encodeURIComponent(professionalId)}`
      : '';

    setLoading(true);
    setError(null);

    call<AppointmentList>(
      'GET',
      `appointments?from=${encodeURIComponent(period.from)}&to=${encodeURIComponent(period.to)}&limit=500${filter}`,
    )
      .then(({ data }) => {
        setRows(data.data);
      })
      .catch(() => {
        setError(t('agenda.loadError'));
      })
      .finally(() => {
        setLoading(false);
      });
  }

  function go(nextMonday: string) {
    setMonday(nextMonday);
    load(nextMonday, professionalFilter);
  }

  function filterBy(professionalId: string) {
    setProfessionalFilter(professionalId);
    load(monday, professionalId);
  }

  function open(next: Dialogs) {
    setDialogError(null);
    setFieldErrors({});
    setNeedsConfirm(false);
    setSlots(null);
    setSlotsError(null);
    setClientQuery('');
    setClientOptions(null);

    if (next?.kind === 'form') {
      const appointment = next.appointment;

      setForm(
        appointment
          ? {
              clientId: appointment.client.id,
              professionalId: appointment.professional.id,
              serviceId: appointment.service?.id ?? '',
              startsAt: toLocalInput(appointment.starts_at),
              endsAt: toLocalInput(appointment.ends_at),
              value: centsToReais(appointment.value_total_cents),
              description: appointment.description ?? '',
              notes: appointment.notes ?? '',
              review:
                appointment.client_review === null
                  ? ''
                  : String(appointment.client_review),
              type: appointment.type,
              session: appointment.session_number?.toString() ?? '',
              sessionsTotal: appointment.sessions_total?.toString() ?? '',
            }
          : {
              ...EMPTY,
              professionalId: professionalFilter || (usable[0]?.id ?? ''),
              startsAt: `${todayInBusiness()}T09:00`,
            },
      );
    }

    setDialog(next);
  }

  async function searchClients() {
    if (clientQuery.trim().length < 2) return;

    try {
      const { data } = await call<ClientPage>(
        'GET',
        `clients?limit=20&q=${encodeURIComponent(clientQuery.trim())}`,
      );
      setClientOptions(data.data);
      setForm((current) => ({
        ...current,
        clientId: data.data[0]?.id ?? '',
      }));
    } catch (failure) {
      setDialogError(explain(failure));
    }
  }

  const editing = dialog?.kind === 'form' ? dialog.appointment : null;
  const rescheduling = dialog?.kind === 'form' && dialog.reschedule === true;
  // Em cancelado, faltoso e finalizado a API só aceita notas (e valor/avaliação no finalizado); reativar só troca a situação.
  const frozen =
    editing !== null &&
    ['completed', 'canceled', 'no_show'].includes(editing.status);
  const selectedService = services.find((s) => s.id === form.serviceId);

  async function showSlots() {
    setSlotsError(null);

    const day = form.startsAt.slice(0, 10);
    const minutes =
      selectedService?.duration_minutes ??
      (form.endsAt && form.startsAt
        ? Math.round(
            (Date.parse(fromLocalInput(form.endsAt)) -
              Date.parse(fromLocalInput(form.startsAt))) /
              60_000,
          )
        : 0);

    if (!form.professionalId || !day || (!selectedService && minutes < 5)) {
      setSlots(null);
      setSlotsError(t('agenda.slotsNeedDay'));

      return;
    }

    const what = selectedService
      ? `service_id=${selectedService.id}`
      : `duration_minutes=${minutes}`;

    try {
      const { data } = await call<{ data: Slot[] }>(
        'GET',
        `professionals/${form.professionalId}/availability?date=${day}&${what}`,
      );
      setSlots({ day, items: data.data });
    } catch {
      setSlots(null);
      setSlotsError(t('agenda.slotsError'));
    }
  }

  async function submit(event?: FormEvent, allowOutside = false) {
    event?.preventDefault();

    if (dialog?.kind !== 'form') return;

    const target = dialog.appointment;
    const cents = reaisToCents(form.value);

    if (Number.isNaN(cents)) {
      setFieldErrors({ value_total_cents: t('agenda.valueHint') });

      return;
    }

    const body: Record<string, unknown> = rescheduling
      ? {
          status: 'scheduled',
          notes: form.notes.trim() === '' ? null : form.notes,
          ...(allowOutside ? { allow_outside_hours: true } : {}),
        }
      : frozen
        ? {
            notes: form.notes.trim() === '' ? null : form.notes,
            ...(seesValues && target?.status === 'completed'
              ? { value_total_cents: cents }
              : {}),
            ...(target?.status === 'completed'
              ? {
                  client_review:
                    form.review === '' ? null : Number(form.review),
                }
              : {}),
          }
        : {
            client_id: form.clientId,
            professional_id: form.professionalId,
            service_id: form.serviceId === '' ? null : form.serviceId,
            starts_at: fromLocalInput(form.startsAt),
            ends_at:
              form.serviceId === '' && form.endsAt
                ? fromLocalInput(form.endsAt)
                : undefined,
            description:
              form.description.trim() === '' ? null : form.description,
            notes: form.notes.trim() === '' ? null : form.notes,
            ...(seesValues ? { value_total_cents: cents } : {}),
            type: form.type,
            session_number: form.session === '' ? null : Number(form.session),
            sessions_total:
              form.sessionsTotal === '' ? null : Number(form.sessionsTotal),
            ...(allowOutside ? { allow_outside_hours: true } : {}),
          };

    if (target && !frozen && !rescheduling) {
      // No agendamento existente o cliente não muda e o fim acompanha o serviço quando ele existe.
      delete body.client_id;
    }

    setSaving(true);
    setDialogError(null);
    setFieldErrors({});
    setNeedsConfirm(false);

    try {
      await (target
        ? call<Appointment>('PATCH', `appointments/${target.id}`, { body })
        : call<Appointment>('POST', 'appointments', { body }));

      setDialog(null);
      load(monday, professionalFilter);
      setNotice({
        tone: 'success',
        text: t(target ? 'agenda.saved' : 'agenda.created'),
      });
    } catch (failure) {
      if (
        failure instanceof ApiError &&
        failure.code === 'outside_availability'
      ) {
        setNeedsConfirm(true);
      } else if (
        failure instanceof ApiError &&
        failure.code === 'appointment_conflict'
      ) {
        setDialogError(t('agenda.conflict'));
      } else if (
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

  async function changeStatus(
    appointment: Appointment,
    status: AppointmentStatus,
  ) {
    // Reativar um cancelado ou faltoso passa pelo diálogo: o horário pode ter mudado e precisa ser conferido.
    if (
      status === 'scheduled' &&
      ['canceled', 'no_show'].includes(appointment.status)
    ) {
      open({ kind: 'form', appointment, reschedule: true });

      return;
    }

    setBusy(true);

    try {
      await call('PATCH', `appointments/${appointment.id}`, {
        body: { status },
      });
      load(monday, professionalFilter);
      setNotice({ tone: 'success', text: t('agenda.statusChanged') });
    } catch (failure) {
      setNotice({
        tone: 'danger',
        text:
          failure instanceof ApiError && failure.code === 'appointment_conflict'
            ? t('agenda.conflict')
            : explain(failure),
      });
    } finally {
      setBusy(false);
    }
  }

  async function remove() {
    if (dialog?.kind !== 'delete') return;

    setSaving(true);
    setDialogError(null);

    try {
      await call('DELETE', `appointments/${dialog.appointment.id}`);
      setDialog(null);
      load(monday, professionalFilter);
      setNotice({ tone: 'success', text: t('agenda.deleted') });
    } catch (failure) {
      setDialogError(explain(failure));
    } finally {
      setSaving(false);
    }
  }

  const days = Array.from({ length: 7 }, (_, index) => addDays(monday, index));
  const byDay = new Map<string, Appointment[]>();

  for (const row of rows) {
    const key = dayOf(row.starts_at);

    byDay.set(key, [...(byDay.get(key) ?? []), row]);
  }

  const deleting = dialog?.kind === 'delete' ? dialog.appointment : null;
  const title = rescheduling
    ? t('agenda.rescheduleTitle')
    : editing
      ? t('agenda.editTitle')
      : t('agenda.newTitle');

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('agenda.title')}
        description={t('agenda.text')}
        actions={
          <Button
            disabled={!canWrite}
            onClick={() => open({ kind: 'form', appointment: null })}
          >
            {t('agenda.new')}
          </Button>
        }
      />
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}
      {me.impersonating ? (
        <Alert tone="info">{t('agenda.supportReadOnly')}</Alert>
      ) : !me.can_write ? (
        <Alert tone="warning">{t('agenda.readOnly')}</Alert>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}

      <div className="flex flex-wrap items-end gap-3">
        <Button
          variant="secondary"
          disabled={loading}
          onClick={() => go(addDays(monday, -7))}
        >
          {t('agenda.prev')}
        </Button>
        <Button
          variant="secondary"
          disabled={loading}
          onClick={() => go(mondayOf(todayInBusiness()))}
        >
          {t('agenda.today')}
        </Button>
        <Button
          variant="secondary"
          disabled={loading}
          onClick={() => go(addDays(monday, 7))}
        >
          {t('agenda.next')}
        </Button>
        {manager || role === 'reception' ? (
          <SelectField
            label={t('agenda.filterProfessional')}
            value={professionalFilter}
            onChange={(event) => filterBy(event.target.value)}
          >
            <option value="">{t('agenda.allProfessionals')}</option>
            {professionals.map((professional) => (
              <option key={professional.id} value={professional.id}>
                {professional.name}
              </option>
            ))}
          </SelectField>
        ) : null}
      </div>

      <p className="text-sm text-muted" aria-live="polite">
        {loading
          ? t('agenda.loading')
          : tf('agenda.week', {
              from: `${range.from.slice(8, 10)}/${range.from.slice(5, 7)}`,
              to: `${addDays(monday, 6).slice(8, 10)}/${addDays(monday, 6).slice(5, 7)}`,
            })}
      </p>

      {rows.length === 0 && !error && !loading ? (
        <EmptyState title={t('agenda.empty')} />
      ) : (
        <div className="space-y-6">
          {days
            .filter((day) => byDay.has(day))
            .map((day) => (
              <section key={day} aria-labelledby={`day-${day}`}>
                <h2
                  id={`day-${day}`}
                  className="mb-2 text-base font-semibold capitalize text-ink"
                >
                  {dayTitle(day)}
                </h2>
                <ul className="space-y-2">
                  {(byDay.get(day) ?? []).map((appointment) => (
                    <li
                      key={appointment.id}
                      className="rounded-md border border-control-border bg-surface p-4"
                    >
                      <div className="flex flex-wrap items-center gap-2 text-sm text-ink">
                        <span
                          aria-hidden="true"
                          className="inline-block size-3 rounded-full border border-control-border"
                          style={{
                            backgroundColor: appointment.professional.color,
                          }}
                        />
                        <span className="font-semibold">
                          {timeOf(appointment.starts_at)}–
                          {timeOf(appointment.ends_at)}
                        </span>
                        <span>{appointment.client.name}</span>
                        <span className="text-muted">
                          {appointment.professional.name}
                          {appointment.service
                            ? ` · ${appointment.service.name}`
                            : ''}
                        </span>
                        {appointment.value_total_cents !== null ? (
                          <span className="text-muted">
                            {formatBRL(appointment.value_total_cents)}
                          </span>
                        ) : null}
                        <Badge tone={STATUS_TONE[appointment.status]}>
                          {t(
                            `agenda.status_${appointment.status}` as MessageKey,
                          )}
                        </Badge>
                      </div>
                      <span className="mt-3 flex flex-wrap gap-2">
                        {NEXT_STATUS[appointment.status].map((status) => (
                          <Button
                            key={status}
                            variant="secondary"
                            disabled={!canWrite || busy}
                            onClick={() =>
                              void changeStatus(appointment, status)
                            }
                            aria-label={`${t(
                              appointment.status === 'canceled' ||
                                appointment.status === 'no_show'
                                ? 'agenda.reschedule'
                                : (`agenda.do_${status}` as MessageKey),
                            )}: ${appointment.client.name}`}
                          >
                            {t(
                              appointment.status === 'canceled' ||
                                appointment.status === 'no_show'
                                ? 'agenda.reschedule'
                                : (`agenda.do_${status}` as MessageKey),
                            )}
                          </Button>
                        ))}
                        <Button
                          variant="secondary"
                          disabled={!canWrite}
                          onClick={() => open({ kind: 'form', appointment })}
                          aria-label={`${t('agenda.edit')}: ${appointment.client.name}`}
                        >
                          {t('agenda.edit')}
                        </Button>
                        {manager && appointment.status !== 'completed' ? (
                          <Button
                            variant="secondary"
                            disabled={!canWrite}
                            onClick={() =>
                              open({ kind: 'delete', appointment })
                            }
                            aria-label={`${t('agenda.delete')}: ${appointment.client.name}`}
                          >
                            {t('agenda.delete')}
                          </Button>
                        ) : null}
                      </span>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
        </div>
      )}

      <Dialog
        open={dialog?.kind === 'form'}
        onClose={() => setDialog(null)}
        title={title}
        description={
          rescheduling
            ? t('agenda.reactivateHint')
            : frozen
              ? t(
                  editing?.status === 'completed'
                    ? 'agenda.completedHint'
                    : 'agenda.closedHint',
                )
              : undefined
        }
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog(null)}>
              {t('agenda.cancelDialog')}
            </Button>
            <Button
              loading={saving}
              disabled={
                !frozen && (form.clientId === '' || form.professionalId === '')
              }
              onClick={() => void submit()}
            >
              {t('agenda.save')}
            </Button>
          </>
        }
      >
        <form className="space-y-4" onSubmit={(event) => void submit(event)}>
          {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
          {needsConfirm ? (
            <Alert tone="warning">
              <p className="font-semibold">{t('agenda.outsideTitle')}</p>
              <p>{t('agenda.outsideText')}</p>
              <Button
                className="mt-2"
                variant="secondary"
                onClick={() => void submit(undefined, true)}
              >
                {t('agenda.outsideConfirm')}
              </Button>
            </Alert>
          ) : null}

          {!frozen ? (
            <>
              {editing ? (
                <p className="text-sm text-ink">
                  <span className="text-muted">{t('agenda.client')}: </span>
                  {editing.client.name}
                </p>
              ) : (
                <div className="space-y-2">
                  <TextField
                    label={t('agenda.clientSearch')}
                    hint={t('agenda.clientSearchHint')}
                    value={clientQuery}
                    onChange={(event) => setClientQuery(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter') {
                        event.preventDefault();
                        void searchClients();
                      }
                    }}
                    error={fieldErrors.client_id}
                  />
                  <Button
                    variant="secondary"
                    disabled={clientQuery.trim().length < 2}
                    onClick={() => void searchClients()}
                  >
                    {t('agenda.clientSearchSubmit')}
                  </Button>
                  {clientOptions !== null ? (
                    clientOptions.length === 0 ? (
                      <p className="text-sm text-muted">
                        {t('agenda.clientNone')}
                      </p>
                    ) : (
                      <SelectField
                        label={t('agenda.clientPick')}
                        value={form.clientId}
                        onChange={(event) =>
                          setForm({ ...form, clientId: event.target.value })
                        }
                      >
                        {clientOptions.map((client) => (
                          <option key={client.id} value={client.id}>
                            {client.name} · {client.phone}
                          </option>
                        ))}
                      </SelectField>
                    )
                  ) : null}
                </div>
              )}

              <SelectField
                label={t('agenda.professionalField')}
                value={form.professionalId}
                error={fieldErrors.professional_id}
                onChange={(event) =>
                  setForm({ ...form, professionalId: event.target.value })
                }
              >
                {editing &&
                !usable.some((p) => p.id === editing.professional.id) ? (
                  <option value={editing.professional.id}>
                    {editing.professional.name}
                  </option>
                ) : null}
                {usable.map((professional) => (
                  <option key={professional.id} value={professional.id}>
                    {professional.name}
                  </option>
                ))}
              </SelectField>
              <SelectField
                label={t('agenda.serviceField')}
                value={form.serviceId}
                error={fieldErrors.service_id}
                onChange={(event) =>
                  setForm({ ...form, serviceId: event.target.value })
                }
              >
                <option value="">{t('agenda.noService')}</option>
                {editing?.service &&
                !services.some((s) => s.id === editing.service?.id) ? (
                  <option value={editing.service.id}>
                    {editing.service.name}
                  </option>
                ) : null}
                {services.map((service) => (
                  <option key={service.id} value={service.id}>
                    {service.name}
                  </option>
                ))}
              </SelectField>
              <TextField
                label={t('agenda.startsAt')}
                type="datetime-local"
                step={300}
                value={form.startsAt}
                error={fieldErrors.starts_at}
                onChange={(event) =>
                  setForm({ ...form, startsAt: event.target.value })
                }
              />
              {form.serviceId === '' ? (
                <TextField
                  label={t('agenda.endsAt')}
                  hint={t('agenda.endsAtHint')}
                  type="datetime-local"
                  step={300}
                  value={form.endsAt}
                  error={fieldErrors.ends_at}
                  onChange={(event) =>
                    setForm({ ...form, endsAt: event.target.value })
                  }
                />
              ) : null}

              <div className="space-y-2">
                <Button variant="secondary" onClick={() => void showSlots()}>
                  {t('agenda.showSlots')}
                </Button>
                {slotsError ? (
                  <p className="text-sm text-danger" role="alert">
                    {slotsError}
                  </p>
                ) : null}
                {slots ? (
                  slots.items.length === 0 ? (
                    <p className="text-sm text-muted">
                      {t('agenda.slotsNone')}
                    </p>
                  ) : (
                    <div>
                      <p className="mb-1 text-sm text-muted">
                        {tf('agenda.slotsFor', {
                          date: `${slots.day.slice(8, 10)}/${slots.day.slice(5, 7)}`,
                        })}
                      </p>
                      <ul className="flex flex-wrap gap-2">
                        {slots.items.map((slot) => (
                          <li key={slot.starts_at}>
                            <Button
                              variant="secondary"
                              onClick={() =>
                                setForm({
                                  ...form,
                                  startsAt: toLocalInput(slot.starts_at),
                                })
                              }
                            >
                              {timeOf(slot.starts_at)}
                            </Button>
                          </li>
                        ))}
                      </ul>
                    </div>
                  )
                ) : null}
              </div>

              <TextField
                label={t('agenda.description')}
                value={form.description}
                maxLength={255}
                error={fieldErrors.description}
                onChange={(event) =>
                  setForm({ ...form, description: event.target.value })
                }
              />
              <SelectField
                label={t('agenda.type')}
                value={form.type}
                error={fieldErrors.type}
                onChange={(event) =>
                  setForm({
                    ...form,
                    type: event.target.value as Form['type'],
                  })
                }
              >
                <option value="appointment">
                  {t('agenda.typeAppointment')}
                </option>
                <option value="retouch">{t('agenda.typeRetouch')}</option>
              </SelectField>
              <div className="grid grid-cols-2 gap-3">
                <TextField
                  label={t('agenda.session')}
                  type="number"
                  min={1}
                  max={99}
                  value={form.session}
                  error={fieldErrors.session_number}
                  onChange={(event) =>
                    setForm({ ...form, session: event.target.value })
                  }
                />
                <TextField
                  label={t('agenda.sessionsTotal')}
                  type="number"
                  min={1}
                  max={99}
                  value={form.sessionsTotal}
                  error={fieldErrors.sessions_total}
                  onChange={(event) =>
                    setForm({ ...form, sessionsTotal: event.target.value })
                  }
                />
              </div>
            </>
          ) : null}

          {seesValues && (!frozen || editing?.status === 'completed') ? (
            <TextField
              label={t('agenda.valueField')}
              hint={t('agenda.valueHint')}
              inputMode="decimal"
              value={form.value}
              error={fieldErrors.value_total_cents}
              onChange={(event) =>
                setForm({ ...form, value: event.target.value })
              }
            />
          ) : null}
          {frozen && editing?.status === 'completed' ? (
            <SelectField
              label={t('agenda.review')}
              value={form.review}
              error={fieldErrors.client_review}
              onChange={(event) =>
                setForm({ ...form, review: event.target.value })
              }
            >
              <option value="">{t('agenda.reviewNone')}</option>
              {[1, 2, 3, 4, 5].map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </SelectField>
          ) : null}
          <TextareaField
            label={t('agenda.notes')}
            value={form.notes}
            maxLength={2000}
            error={fieldErrors.notes}
            onChange={(event) =>
              setForm({ ...form, notes: event.target.value })
            }
          />
        </form>
      </Dialog>

      <Dialog
        open={deleting !== null}
        onClose={() => setDialog(null)}
        title={t('agenda.deleteTitle')}
        description={
          deleting
            ? tf('agenda.deleteText', {
                client: deleting.client.name,
                when: `${dayOf(deleting.starts_at).slice(8, 10)}/${dayOf(deleting.starts_at).slice(5, 7)} ${timeOf(deleting.starts_at)}`,
              })
            : undefined
        }
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog(null)}>
              {t('agenda.cancelDialog')}
            </Button>
            <Button loading={saving} onClick={() => void remove()}>
              {t('agenda.deleteConfirm')}
            </Button>
          </>
        }
      >
        {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
      </Dialog>
    </div>
  );
}
