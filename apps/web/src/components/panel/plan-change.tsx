'use client';

import { useRouter } from 'next/navigation';
import { useRef, useState } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { formatBRL, formatDate } from '@/lib/format';
import type { PublicPlan } from '@/lib/plans';
import type { PlanChangePreview, Subscription } from '@/lib/panel-types';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardHeader } from '@/components/ui/card';
import { Dialog } from '@/components/ui/dialog';

function newKey(): string {
  return (
    globalThis.crypto.randomUUID?.() ??
    Array.from(globalThis.crypto.getRandomValues(new Uint8Array(16)), (b) =>
      b.toString(16).padStart(2, '0'),
    ).join('')
  );
}

type Loaded = { plans: PublicPlan[] } | { error: string };

/**
 * Trocar de plano (dona): mostra o resumo antes de confirmar (quanto cobra agora, quando vale e se o uso passa do
 * limite do plano novo) e mostra a troca agendada, que pode ser desfeita. O ciclo continua o mesmo.
 */
export function PlanChangeCard({
  subscription,
  disabled,
  onNotice,
}: {
  subscription: Subscription;
  disabled: boolean;
  onNotice: (notice: { tone: 'success' | 'danger'; text: string }) => void;
}) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [loaded, setLoaded] = useState<Loaded | null>(null);
  const [chosen, setChosen] = useState<string | null>(null);
  const [preview, setPreview] = useState<PlanChangePreview | null>(null);
  const [previewing, setPreviewing] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const attempt = useRef<{ plan: string; key: string } | null>(null);
  // Plano da simulação mais recente: respostas atrasadas de outro plano são ignoradas.
  const latest = useRef<string | null>(null);

  const pending = subscription.pending_plan_change ?? null;
  const changeable =
    ['trialing', 'active'].includes(subscription.status) &&
    !subscription.cancel_at_period_end;
  const trialing = subscription.status === 'trialing';

  async function openDialog() {
    setOpen(true);
    attempt.current = null;
    latest.current = null;
    setChosen(null);
    setPreview(null);
    setError(null);
    setLoaded(null);

    try {
      const { data } = await call<{ data: PublicPlan[] } | PublicPlan[]>(
        'GET',
        'plans',
      );
      const list = Array.isArray(data) ? data : data.data;
      setLoaded({
        plans: list.filter((plan) => plan.key !== subscription.plan.key),
      });
    } catch {
      setLoaded({ error: t('planChange.plansError') });
    }
  }

  async function choose(key: string) {
    latest.current = key;
    setChosen(key);
    setPreview(null);
    setError(null);
    setPreviewing(true);

    try {
      const { data } = await call<PlanChangePreview>(
        'GET',
        `subscription/plan-change/preview?plan=${encodeURIComponent(key)}`,
      );
      if (latest.current === key) setPreview(data);
    } catch (failure) {
      if (latest.current === key) setError(explain(failure));
    } finally {
      if (latest.current === key) setPreviewing(false);
    }
  }

  function explain(failure: unknown): string {
    if (failure instanceof ApiError) {
      if (failure.status === 402) return t('planChange.declined');
      if (failure.code === 'plan_limit_exceeded')
        return t('planChange.limitChanged');
      if (failure.code === 'cancel_pending')
        return t('planChange.cancelPendingFirst');
      if (failure.code === 'subscription_suspended')
        return t('planChange.suspendedFirst');

      return failure.message;
    }

    return t('common.genericError');
  }

  async function confirm() {
    if (!chosen || !preview) return;

    if (attempt.current?.plan !== chosen) {
      attempt.current = { plan: chosen, key: newKey() };
    }

    setBusy(true);
    setError(null);

    try {
      await call('PUT', 'subscription/plan', {
        body: { plan: chosen },
        idempotencyKey: attempt.current.key,
      });
      // Pedido concluído: a próxima troca (mesmo para o mesmo plano) é outro pedido.
      attempt.current = null;
      setOpen(false);
      onNotice({
        tone: 'success',
        text:
          preview.kind === 'now'
            ? tf('planChange.done', { plan: preview.plan.name })
            : tf('planChange.scheduled', {
                date: formatDate(preview.effective_at),
              }),
      });
      router.refresh();
    } catch (failure) {
      // Depois de uma recusa, a nova tentativa é outro pedido (a chave antiga devolveria a resposta antiga).
      if (!(failure instanceof ApiError && failure.isNetwork)) {
        attempt.current = null;
      }

      setError(explain(failure));
    } finally {
      setBusy(false);
    }
  }

  async function undo() {
    setBusy(true);

    try {
      await call('DELETE', 'subscription/plan-change');
      onNotice({ tone: 'success', text: t('planChange.undone') });
      router.refresh();
    } catch (failure) {
      onNotice({ tone: 'danger', text: explain(failure) });
    } finally {
      setBusy(false);
    }
  }

  const summary = preview
    ? preview.kind === 'next_cycle'
      ? tf('planChange.nextCycle', {
          date: formatDate(preview.effective_at),
          price: formatBRL(preview.price_cents),
        })
      : trialing
        ? tf('planChange.trial', { price: formatBRL(preview.price_cents) })
        : preview.charge_now_cents > 0
          ? tf('planChange.now', {
              charge: formatBRL(preview.charge_now_cents),
              price: formatBRL(preview.price_cents),
            })
          : tf('planChange.nowFree', { price: formatBRL(preview.price_cents) })
    : null;

  return (
    <Card>
      <CardHeader title={t('planChange.title')} />
      <p className="text-sm text-ink">
        {subscription.plan.name}
        {pending ? (
          <>
            {' · '}
            {tf('planChange.pending', {
              plan: pending.plan.name,
              date: formatDate(pending.effective_at),
              price: formatBRL(pending.price_cents),
            })}
          </>
        ) : null}
      </p>
      <div className="mt-4 flex flex-wrap gap-2">
        {changeable ? (
          <Button
            variant="secondary"
            disabled={disabled}
            onClick={() => void openDialog()}
          >
            {t('planChange.change')}
          </Button>
        ) : null}
        {pending ? (
          <Button
            variant="secondary"
            disabled={disabled}
            loading={busy && !open}
            onClick={() => void undo()}
          >
            {t('planChange.undo')}
          </Button>
        ) : null}
      </div>

      <Dialog
        open={open}
        onClose={() => setOpen(false)}
        title={t('planChange.dialogTitle')}
        description={tf('planChange.dialogText', {
          cycle: t(
            subscription.cycle === 'monthly'
              ? 'subscription.monthly'
              : 'subscription.yearly',
          ).toLowerCase(),
        })}
      >
        <div className="flex flex-col gap-4">
          {error ? <Alert tone="danger">{error}</Alert> : null}
          {loaded === null ? (
            <p className="text-muted">{t('planChange.loadingPlans')}</p>
          ) : 'error' in loaded ? (
            <Alert tone="danger">{loaded.error}</Alert>
          ) : loaded.plans.length === 0 ? (
            <p className="text-muted">{t('planChange.noOthers')}</p>
          ) : (
            <fieldset className="flex flex-col gap-2">
              <legend className="mb-1 font-medium text-ink">
                {t('planChange.choose')}
              </legend>
              {loaded.plans.map((plan) => {
                const price =
                  subscription.cycle === 'yearly'
                    ? plan.yearly_price_cents
                    : plan.monthly_price_cents;

                return (
                  <label
                    key={plan.key}
                    className="flex min-h-11 cursor-pointer items-center gap-3 rounded-md border border-control-border px-3 py-2"
                  >
                    <input
                      type="radio"
                      name="novo-plano"
                      value={plan.key}
                      checked={chosen === plan.key}
                      onChange={() => void choose(plan.key)}
                    />
                    <span className="flex-1 font-medium text-ink">
                      {plan.name}
                    </span>
                    <span className="text-sm text-muted">
                      {tf('planChange.perCycle', { price: formatBRL(price) })}
                    </span>
                  </label>
                );
              })}
            </fieldset>
          )}

          {previewing ? (
            <p className="text-muted">{t('planChange.previewLoading')}</p>
          ) : null}
          {preview && summary ? (
            <div aria-live="polite" className="space-y-3">
              <h3 className="font-semibold text-ink">
                {t('planChange.preview')}
              </h3>
              {preview.violations.length > 0 ? (
                <Alert tone="warning" title={t('planChange.blocked')}>
                  <ul className="list-disc pl-5">
                    {preview.violations.map((item) => (
                      <li key={item.feature}>
                        {tf(
                          item.feature === 'usuarios.max'
                            ? 'planChange.users'
                            : 'planChange.other',
                          {
                            feature: item.feature,
                            limit: item.limit,
                            usage: item.usage,
                          },
                        )}
                      </li>
                    ))}
                  </ul>
                  <p className="mt-2">{t('planChange.blockedHint')}</p>
                </Alert>
              ) : (
                <p className="text-sm text-ink">{summary}</p>
              )}
            </div>
          ) : null}

          <div className="flex flex-wrap justify-end gap-2">
            <Button
              variant="secondary"
              onClick={() => setOpen(false)}
              disabled={busy}
            >
              {t('common.cancel')}
            </Button>
            <Button
              loading={busy}
              disabled={!preview || preview.violations.length > 0}
              onClick={() => void confirm()}
            >
              {t('planChange.confirm')}
            </Button>
          </div>
        </div>
      </Dialog>
    </Card>
  );
}
