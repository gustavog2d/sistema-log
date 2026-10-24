'use client';

import { useState, type FormEvent } from 'react';
import { t, tf, type MessageKey } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import {
  addMonths,
  formatDay,
  monthRange,
  monthTitle,
} from '@/lib/expense-types';
import {
  goalProgress,
  weeksOf,
  type FinanceCalendar,
  type FinanceData,
  type FinanceMonthly,
  type FinanceSummary,
} from '@/lib/finance-types';
import { formatBRL } from '@/lib/format';
import { centsToReais, reaisToCents } from '@/lib/money';
import type { Me } from '@/lib/panel-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';

function Card({
  label,
  value,
  hint,
  strong = false,
}: {
  label: string;
  value: string;
  hint: string;
  strong?: boolean;
}) {
  return (
    <div className="rounded-md border border-control-border bg-surface p-4">
      <p className="text-sm text-muted">{label}</p>
      <p
        className={
          strong
            ? 'text-2xl font-bold text-ink'
            : 'text-xl font-semibold text-ink'
        }
      >
        {value}
      </p>
      <p className="mt-1 text-xs text-muted">{hint}</p>
    </div>
  );
}

/** Barra de proporção: a largura é só reforço, o valor sempre aparece em texto ao lado. */
function Bar({ value, max }: { value: number; max: number }) {
  const width = max > 0 ? Math.max(0, Math.min(100, (value / max) * 100)) : 0;

  return (
    <span
      aria-hidden="true"
      className="block h-2 rounded-full bg-control-border"
    >
      <span
        className="block h-2 rounded-full bg-brand-600"
        style={{ width: `${width}%` }}
      />
    </span>
  );
}

/** Dashboard financeiro (FIN-003): mês escolhido, meta, balanço de 12 meses e calendário. Dona e gerente. */
export function FinanceView({
  me,
  initial,
  goal: initialGoal,
  month: initialMonth,
}: {
  me: Me;
  initial: FinanceData | null;
  goal: number | null;
  /** Mês inicial (`aaaa-mm`), calculado no servidor. */
  month: string;
}) {
  const [month, setMonth] = useState(initialMonth);
  const [data, setData] = useState<FinanceData | null>(initial);
  const [goal, setGoal] = useState(initialGoal);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('finance.loadError'),
  );
  const [notice, setNotice] = useState<string | null>(null);
  const [goalOpen, setGoalOpen] = useState(false);
  const [goalText, setGoalText] = useState('');
  const [goalError, setGoalError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const canWrite = me.can_write && !me.impersonating;

  function load(nextMonth: string) {
    const range = monthRange(nextMonth);

    setLoading(true);
    setError(null);

    Promise.all([
      call<FinanceSummary>(
        'GET',
        `finance/summary?from=${range.from}&to=${range.to}`,
      ),
      call<FinanceMonthly>('GET', `finance/monthly?to=${nextMonth}`),
      call<FinanceCalendar>('GET', `finance/calendar?month=${nextMonth}`),
    ])
      .then(([summary, monthly, calendar]) => {
        setData({
          summary: summary.data,
          monthly: monthly.data,
          calendar: calendar.data,
        });
      })
      .catch(() => {
        setError(t('finance.loadError'));
      })
      .finally(() => {
        setLoading(false);
      });
  }

  function go(nextMonth: string) {
    setMonth(nextMonth);
    load(nextMonth);
  }

  function openGoal() {
    setGoalError(null);
    setGoalText(goal === null ? '' : centsToReais(goal));
    setGoalOpen(true);
  }

  async function saveGoal(event?: FormEvent) {
    event?.preventDefault();

    const cents = reaisToCents(goalText);

    if (Number.isNaN(cents)) {
      setGoalError(t('finance.goalField'));

      return;
    }

    setSaving(true);
    setGoalError(null);

    try {
      const { data: saved } = await call<{ monthly_goal_cents: number | null }>(
        'PUT',
        'finance/goal',
        { body: { monthly_goal_cents: cents } },
      );
      setGoal(saved.monthly_goal_cents);
      setGoalOpen(false);
      setNotice(
        t(
          saved.monthly_goal_cents === null
            ? 'finance.goalRemoved'
            : 'finance.goalSaved',
        ),
      );
    } catch (failure) {
      setGoalError(
        failure instanceof ApiError
          ? (failure.fieldErrors.monthly_goal_cents ?? failure.message)
          : t('common.genericError'),
      );
    } finally {
      setSaving(false);
    }
  }

  const summary = data?.summary;
  const progress = summary ? goalProgress(summary.received_cents, goal) : null;
  const weekdays = t('finance.weekdays').split(',');
  const maxYear = data
    ? Math.max(
        1,
        ...data.monthly.months.map((m) =>
          Math.max(m.received_cents, m.expenses_cents),
        ),
      )
    : 1;

  return (
    <div className="space-y-8">
      <PageHeader title={t('finance.title')} description={t('finance.text')} />
      {notice ? <Alert tone="success">{notice}</Alert> : null}
      {me.impersonating ? (
        <Alert tone="info">{t('finance.supportReadOnly')}</Alert>
      ) : !me.can_write ? (
        <Alert tone="warning">{t('finance.readOnly')}</Alert>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}

      <div className="flex flex-wrap items-center gap-3">
        <Button
          variant="secondary"
          disabled={loading}
          onClick={() => go(addMonths(month, -1))}
        >
          {t('finance.prev')}
        </Button>
        <h2 className="text-lg font-semibold capitalize text-ink">
          {monthTitle(month)}
        </h2>
        <Button
          variant="secondary"
          disabled={loading}
          onClick={() => go(addMonths(month, 1))}
        >
          {t('finance.next')}
        </Button>
      </div>
      <p className="text-sm text-muted" aria-live="polite">
        {loading ? t('finance.loading') : null}
      </p>

      {summary ? (
        <>
          <section aria-labelledby="fin-cards" className="space-y-3">
            <h2 id="fin-cards" className="sr-only">
              {t('finance.summaryTitle')}
            </h2>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Card
                strong
                label={t('finance.received')}
                value={formatBRL(summary.received_cents)}
                hint={t('finance.receivedHint')}
              />
              <Card
                label={t('finance.expected')}
                value={formatBRL(summary.expected_cents)}
                hint={t('finance.expectedHint')}
              />
              <Card
                label={t('finance.toReceive')}
                value={formatBRL(summary.to_receive_cents)}
                hint={t('finance.toReceiveHint')}
              />
              <Card
                label={t('finance.net')}
                value={formatBRL(summary.net_received_cents)}
                hint={tf('finance.netHint', {
                  commission: formatBRL(summary.commission_received_cents),
                })}
              />
              <Card
                label={t('finance.netExpected')}
                value={formatBRL(summary.net_expected_cents)}
                hint={tf('finance.netExpectedHint', {
                  commission: formatBRL(summary.commission_expected_cents),
                })}
              />
              <Card
                label={t('finance.expenses')}
                value={formatBRL(summary.expenses_cents)}
                hint={t('finance.expensesHint')}
              />
            </div>
            <div className="rounded-md border border-control-border bg-surface p-4">
              <p className="text-sm text-muted">{t('finance.result')}</p>
              <p className="text-2xl font-bold text-ink">
                {formatBRL(summary.result_cents)}
              </p>
              <p className="mt-1 text-xs text-muted">
                {t('finance.resultHint')} ·{' '}
                {tf('finance.appointments', {
                  count: summary.appointments_count,
                })}
              </p>
            </div>
          </section>

          <section aria-labelledby="fin-goal" className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <h2 id="fin-goal" className="text-lg font-semibold text-ink">
                {t('finance.goalTitle')}
              </h2>
              <Button
                variant="secondary"
                disabled={!canWrite}
                onClick={openGoal}
              >
                {t(goal === null ? 'finance.goalSet' : 'finance.goalEdit')}
              </Button>
            </div>
            {goal === null || progress === null ? (
              <p className="text-muted">{t('finance.goalNone')}</p>
            ) : (
              <div className="space-y-2">
                <p className="text-ink">
                  {tf('finance.goalProgress', {
                    received: formatBRL(summary.received_cents),
                    goal: formatBRL(goal),
                    percent: progress,
                  })}
                </p>
                <progress
                  className="h-3 w-full"
                  max={100}
                  value={Math.min(progress, 100)}
                  aria-label={t('finance.goalProgressLabel')}
                />
              </div>
            )}
          </section>

          <section aria-labelledby="fin-methods" className="space-y-3">
            <h2 id="fin-methods" className="text-lg font-semibold text-ink">
              {t('finance.byMethod')}
            </h2>
            {summary.by_method.length === 0 ? (
              <p className="text-muted">{t('finance.byMethodEmpty')}</p>
            ) : (
              <ul className="space-y-1 text-ink">
                {summary.by_method.map((row) => (
                  <li key={row.method} className="flex justify-between gap-3">
                    <span>
                      {t(`finance.method_${row.method}` as MessageKey)}
                    </span>
                    <span className="font-medium">
                      {formatBRL(row.total_cents)}
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        </>
      ) : null}

      {data ? (
        <>
          <section aria-labelledby="fin-year" className="space-y-3">
            <h2 id="fin-year" className="text-lg font-semibold text-ink">
              {t('finance.yearTitle')}
            </h2>
            <Table caption={t('finance.yearCaption')}>
              <thead>
                <Tr>
                  <Th>{t('finance.month')}</Th>
                  <Th>{t('finance.colReceived')}</Th>
                  <Th>{t('finance.colNet')}</Th>
                  <Th>{t('finance.colExpected')}</Th>
                  <Th>{t('finance.colExpenses')}</Th>
                  <Th>{t('finance.colResult')}</Th>
                </Tr>
              </thead>
              <tbody>
                {data.monthly.months.map((row) => (
                  <Tr key={row.month}>
                    <Td>
                      <span className="capitalize">
                        {monthTitle(row.month)}
                      </span>
                    </Td>
                    <Td>
                      {formatBRL(row.received_cents)}
                      <Bar value={row.received_cents} max={maxYear} />
                    </Td>
                    <Td>{formatBRL(row.net_received_cents)}</Td>
                    <Td>{formatBRL(row.expected_cents)}</Td>
                    <Td>
                      {formatBRL(row.expenses_cents)}
                      <Bar value={row.expenses_cents} max={maxYear} />
                    </Td>
                    <Td>{formatBRL(row.result_cents)}</Td>
                  </Tr>
                ))}
                <Tr>
                  <Td>
                    <strong>{t('finance.totals')}</strong>
                  </Td>
                  <Td>{formatBRL(data.monthly.totals.received_cents)}</Td>
                  <Td>{formatBRL(data.monthly.totals.net_received_cents)}</Td>
                  <Td>{formatBRL(data.monthly.totals.expected_cents)}</Td>
                  <Td>{formatBRL(data.monthly.totals.expenses_cents)}</Td>
                  <Td>{formatBRL(data.monthly.totals.result_cents)}</Td>
                </Tr>
              </tbody>
            </Table>
          </section>

          <section aria-labelledby="fin-calendar" className="space-y-3">
            <h2 id="fin-calendar" className="text-lg font-semibold text-ink">
              {t('finance.calendarTitle')}
            </h2>
            <Table caption={t('finance.calendarCaption')}>
              <thead>
                <Tr>
                  {weekdays.map((name) => (
                    <Th key={name}>{name}</Th>
                  ))}
                </Tr>
              </thead>
              <tbody>
                {weeksOf(data.calendar.days).map((week, index) => (
                  <Tr key={index}>
                    {week.map((day, cell) => (
                      <Td key={cell}>
                        {day ? (
                          <span className="block text-xs text-ink">
                            <strong>{formatDay(day.date).slice(0, 2)}</strong>
                            {day.received_cents === 0 &&
                            day.expected_cents === 0 &&
                            day.expenses_cents === 0 ? (
                              <span className="block text-muted">
                                {t('finance.dayEmpty')}
                              </span>
                            ) : (
                              <>
                                {day.received_cents > 0 ? (
                                  <span className="block">
                                    {tf('finance.dayIn', {
                                      value: formatBRL(day.received_cents),
                                    })}
                                  </span>
                                ) : null}
                                {day.expected_cents > 0 ? (
                                  <span className="block">
                                    {tf('finance.dayExpected', {
                                      value: formatBRL(day.expected_cents),
                                    })}{' '}
                                    ·{' '}
                                    {tf('finance.dayCount', {
                                      count: day.appointments_count,
                                    })}
                                  </span>
                                ) : null}
                                {day.expenses_cents > 0 ? (
                                  <span className="block">
                                    {tf('finance.dayOut', {
                                      value: formatBRL(day.expenses_cents),
                                    })}
                                  </span>
                                ) : null}
                              </>
                            )}
                          </span>
                        ) : null}
                      </Td>
                    ))}
                  </Tr>
                ))}
              </tbody>
            </Table>
          </section>
        </>
      ) : null}

      <Dialog
        open={goalOpen}
        onClose={() => setGoalOpen(false)}
        title={t('finance.goalTitle')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setGoalOpen(false)}>
              {t('finance.goalCancel')}
            </Button>
            <Button loading={saving} onClick={() => void saveGoal()}>
              {t('finance.goalSave')}
            </Button>
          </>
        }
      >
        <form onSubmit={(event) => void saveGoal(event)}>
          <TextField
            label={t('finance.goalField')}
            hint={t('finance.goalHint')}
            inputMode="decimal"
            value={goalText}
            error={goalError ?? undefined}
            onChange={(event) => setGoalText(event.target.value)}
          />
        </form>
      </Dialog>
    </div>
  );
}
