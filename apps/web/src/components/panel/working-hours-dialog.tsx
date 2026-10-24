'use client';

import { useState } from 'react';
import { t, tf, type MessageKey } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import {
  WEEK_ORDER,
  type Professional,
  type WorkingRange,
} from '@/lib/professional-types';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';

type Draft = Record<number, Array<{ start: string; end: string }>>;

function toDraft(ranges: WorkingRange[]): Draft {
  const draft: Draft = {};

  for (const range of ranges) {
    (draft[range.weekday] ??= []).push({ start: range.start, end: range.end });
  }

  return draft;
}

function toRanges(draft: Draft): WorkingRange[] {
  return WEEK_ORDER.flatMap((weekday) =>
    (draft[weekday] ?? []).map((range) => ({ weekday, ...range })),
  );
}

const minutes = (clock: string) =>
  Number(clock.slice(0, 2)) * 60 + Number(clock.slice(3, 5));

/** Confere antes de enviar (a API confere de novo): fim depois do início, de 5 em 5 minutos e sem sobreposição no dia. */
export function validateRanges(draft: Draft): MessageKey | null {
  for (const weekday of WEEK_ORDER) {
    const ranges = [...(draft[weekday] ?? [])].sort(
      (a, b) => minutes(a.start) - minutes(b.start),
    );

    for (const [index, range] of ranges.entries()) {
      if (
        !/^([01]\d|2[0-3]):[0-5]\d$/.test(range.start) ||
        !/^(([01]\d|2[0-3]):[0-5]\d|24:00)$/.test(range.end) ||
        minutes(range.end) <= minutes(range.start)
      ) {
        return 'professionals.hoursOrder';
      }

      if (minutes(range.start) % 5 !== 0 || minutes(range.end) % 5 !== 0) {
        return 'professionals.hoursStep';
      }

      if (index > 0 && minutes(range.start) < minutes(ranges[index - 1]!.end)) {
        return 'professionals.hoursOverlap';
      }
    }
  }

  return null;
}

/** Expediente semanal de um profissional: várias faixas por dia, copiar um dia para os dias úteis. Quem não escreve só vê. */
export function WorkingHoursDialog({
  professional,
  initial,
  canWrite,
  onClose,
  onSaved,
}: {
  professional: Professional | null;
  initial: WorkingRange[];
  canWrite: boolean;
  onClose: () => void;
  onSaved: (message: string) => void;
}) {
  const [draft, setDraft] = useState<Draft>(() => toDraft(initial));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function change(
    weekday: number,
    index: number,
    field: 'start' | 'end',
    value: string,
  ) {
    setDraft((current) => ({
      ...current,
      [weekday]: (current[weekday] ?? []).map((range, position) =>
        position === index ? { ...range, [field]: value } : range,
      ),
    }));
  }

  function add(weekday: number) {
    setDraft((current) => ({
      ...current,
      [weekday]: [
        ...(current[weekday] ?? []),
        { start: '09:00', end: '18:00' },
      ],
    }));
  }

  function remove(weekday: number, index: number) {
    setDraft((current) => ({
      ...current,
      [weekday]: (current[weekday] ?? []).filter(
        (_, position) => position !== index,
      ),
    }));
  }

  /** Copia as faixas do dia para segunda a sexta (os outros dias úteis). */
  function copyToWeekdays(weekday: number) {
    setDraft((current) => {
      const next = { ...current };

      for (const day of [1, 2, 3, 4, 5]) {
        next[day] = (current[weekday] ?? []).map((range) => ({ ...range }));
      }

      return next;
    });
  }

  async function save() {
    if (!professional) return;

    const problem = validateRanges(draft);

    if (problem) {
      setError(t(problem));

      return;
    }

    setBusy(true);
    setError(null);

    try {
      await call('PUT', `professionals/${professional.id}/working-hours`, {
        body: { ranges: toRanges(draft) },
      });
      onSaved(t('professionals.hoursSaved'));
    } catch (failure) {
      setError(
        failure instanceof ApiError && failure.status === 403
          ? t('panel.noPermission')
          : failure instanceof ApiError
            ? failure.message
            : t('common.genericError'),
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <Dialog
      open={professional !== null}
      onClose={onClose}
      title={
        professional
          ? tf('professionals.hoursTitle', { name: professional.name })
          : ''
      }
      description={t('professionals.hoursText')}
    >
      <div className="flex flex-col gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <ul className="divide-y divide-control-border rounded-md border border-control-border">
          {WEEK_ORDER.map((weekday) => {
            const ranges = draft[weekday] ?? [];
            const day = t(`professionals.days.${weekday}` as MessageKey);

            return (
              <li key={weekday} className="space-y-2 p-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-medium text-ink">{day}</span>
                  {ranges.length === 0 ? (
                    <span className="text-sm text-muted">
                      {t('professionals.hoursEmpty')}
                    </span>
                  ) : null}
                  {canWrite ? (
                    <span className="flex flex-wrap gap-2">
                      <Button
                        variant="secondary"
                        onClick={() => add(weekday)}
                        aria-label={`${t('professionals.addRange')}: ${day}`}
                      >
                        {t('professionals.addRange')}
                      </Button>
                      {ranges.length > 0 ? (
                        <Button
                          variant="secondary"
                          onClick={() => copyToWeekdays(weekday)}
                          aria-label={`${t('professionals.copyDay')}: ${day}`}
                        >
                          {t('professionals.copyDay')}
                        </Button>
                      ) : null}
                    </span>
                  ) : null}
                </div>
                {ranges.map((range, index) => (
                  <div
                    key={index}
                    className="flex flex-wrap items-center gap-2"
                  >
                    <label className="flex items-center gap-1 text-sm">
                      <span>{t('professionals.from')}</span>
                      <input
                        type="text"
                        inputMode="numeric"
                        placeholder="09:00"
                        maxLength={5}
                        aria-label={`${t('professionals.start')} ${index + 1}: ${day}`}
                        className="min-h-11 w-24 rounded-md border border-control-border px-2"
                        value={range.start}
                        disabled={!canWrite}
                        onChange={(event) =>
                          change(weekday, index, 'start', event.target.value)
                        }
                      />
                    </label>
                    <label className="flex items-center gap-1 text-sm">
                      <span>{t('professionals.to')}</span>
                      <input
                        type="text"
                        inputMode="numeric"
                        placeholder="18:00"
                        maxLength={5}
                        aria-label={`${t('professionals.end')} ${index + 1}: ${day}`}
                        className="min-h-11 w-24 rounded-md border border-control-border px-2"
                        value={range.end}
                        disabled={!canWrite}
                        onChange={(event) =>
                          change(weekday, index, 'end', event.target.value)
                        }
                      />
                    </label>
                    {canWrite ? (
                      <Button
                        variant="secondary"
                        onClick={() => remove(weekday, index)}
                        aria-label={`${t('professionals.removeRange')} ${index + 1}: ${day}`}
                      >
                        {t('professionals.removeRange')}
                      </Button>
                    ) : null}
                  </div>
                ))}
              </li>
            );
          })}
        </ul>
        <div className="flex flex-wrap justify-end gap-2">
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            {t('common.close')}
          </Button>
          {canWrite ? (
            <Button loading={busy} onClick={() => void save()}>
              {t('professionals.hoursSave')}
            </Button>
          ) : null}
        </div>
      </div>
    </Dialog>
  );
}
