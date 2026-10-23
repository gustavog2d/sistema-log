'use client';

import { useRouter } from 'next/navigation';
import { useRef, useState } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { cn } from '@/lib/cn';
import { formatBRL, formatDate } from '@/lib/format';
import type { Invoice, Me, Subscription } from '@/lib/panel-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader } from '@/components/ui/card';
import { Dialog } from '@/components/ui/dialog';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { StatusBadge } from './status-badge';

export type InvoicePage = { data: Invoice[]; next_cursor: string | null };

const TEST_CARDS = [
  { token: 'tok_ok', label: 'subscription.payOk' },
  { token: 'tok_fail_on_charge', label: 'subscription.payFail' },
  { token: 'tok_declined', label: 'subscription.payDeclined' },
] as const;

const paymentIsFake = () => process.env.NEXT_PUBLIC_PAYMENT_MODE === 'fake';

function newKey(): string {
  return (
    globalThis.crypto.randomUUID?.() ??
    Array.from(globalThis.crypto.getRandomValues(new Uint8Array(16)), (b) =>
      b.toString(16).padStart(2, '0'),
    ).join('')
  );
}

/** Assinatura da dona: situação, meio de pagamento (trocar e regularizar), cancelamento e faturas. */
export function SubscriptionView({
  me,
  subscription,
  invoices: initialInvoices,
}: {
  me: Me;
  subscription: Subscription;
  invoices: InvoicePage;
}) {
  const router = useRouter();
  const [invoices, setInvoices] = useState(initialInvoices);
  const [loadingMore, setLoadingMore] = useState(false);
  const [notice, setNotice] = useState<{
    tone: 'success' | 'danger';
    text: string;
  } | null>(null);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [token, setToken] = useState('');
  const [busy, setBusy] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const attempt = useRef<{ token: string; key: string } | null>(null);

  const blocked = me.impersonating;
  const canceled = subscription.status === 'canceled';
  const suspended = subscription.status === 'suspended';
  const until =
    subscription.status === 'trialing'
      ? subscription.trial_ends_at
      : subscription.current_period_end;

  async function changePayment() {
    if (!token) {
      setDialogError(t('signup.required'));

      return;
    }

    if (attempt.current?.token !== token) {
      attempt.current = { token, key: newKey() };
    }

    setBusy(true);
    setDialogError(null);

    try {
      await call('PUT', 'subscription/payment-method', {
        body: { payment_method_token: token },
        idempotencyKey: attempt.current.key,
      });
      setPaymentOpen(false);
      setToken('');
      setNotice({
        tone: 'success',
        text: suspended
          ? t('subscription.regularized')
          : t('subscription.changed'),
      });
      router.refresh();
    } catch (error) {
      // Depois de uma recusa, a nova tentativa é outro pedido (a chave antiga devolveria a resposta antiga).
      if (!(error instanceof ApiError && error.isNetwork)) {
        attempt.current = null;
      }

      if (error instanceof ApiError && error.status === 402) {
        setDialogError(t('subscription.declined'));
      } else if (
        error instanceof ApiError &&
        error.code === 'suspended_by_support'
      ) {
        setDialogError(t('subscription.suspendedByStaff'));
      } else if (
        error instanceof ApiError &&
        error.fieldErrors.payment_method_token
      ) {
        setDialogError(t('subscription.declined'));
      } else {
        setDialogError(
          error instanceof ApiError ? error.message : t('common.genericError'),
        );
      }
    } finally {
      setBusy(false);
    }
  }

  async function act(
    path: 'subscription/cancel' | 'subscription/reactivate',
    success: string,
  ) {
    setBusy(true);
    setDialogError(null);

    try {
      await call('POST', path);
      setCancelOpen(false);
      setNotice({ tone: 'success', text: success });
      router.refresh();
    } catch (error) {
      setCancelOpen(false);
      setNotice({
        tone: 'danger',
        text:
          error instanceof ApiError ? error.message : t('common.genericError'),
      });
    } finally {
      setBusy(false);
    }
  }

  async function loadMore() {
    if (!invoices.next_cursor) {
      return;
    }

    setLoadingMore(true);

    try {
      const { data } = await call<InvoicePage>(
        'GET',
        `subscription/invoices?limit=20&cursor=${encodeURIComponent(invoices.next_cursor)}`,
      );
      setInvoices((current) => ({
        data: [...current.data, ...data.data],
        next_cursor: data.next_cursor,
      }));
    } catch (error) {
      setNotice({
        tone: 'danger',
        text:
          error instanceof ApiError ? error.message : t('common.genericError'),
      });
    } finally {
      setLoadingMore(false);
    }
  }

  const cancelBody = suspended
    ? t('subscription.cancelBodySuspended')
    : tf(
        subscription.status === 'trialing'
          ? 'subscription.cancelBodyTrial'
          : 'subscription.cancelBodyActive',
        { date: until ? formatDate(until) : '' },
      );

  return (
    <div className="space-y-6">
      <PageHeader title={t('subscription.title')} />
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}
      {blocked ? (
        <Alert tone="info">{t('subscription.blockedSupport')}</Alert>
      ) : null}

      <Card>
        <dl className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt className="text-muted">{t('subscription.plan')}</dt>
            <dd className="font-semibold text-ink">{subscription.plan.name}</dd>
          </div>
          <div>
            <dt className="text-muted">{t('subscription.cycle')}</dt>
            <dd className="font-semibold text-ink">
              {t(
                subscription.cycle === 'monthly'
                  ? 'subscription.monthly'
                  : 'subscription.yearly',
              )}
            </dd>
          </div>
          <div>
            <dt className="text-muted">{t('subscription.price')}</dt>
            <dd className="font-semibold text-ink">
              {formatBRL(subscription.price_cents)}
            </dd>
          </div>
          <div>
            <dt className="text-muted">
              {subscription.cancel_at_period_end
                ? t('subscription.periodEnd')
                : t('subscription.nextCharge')}
            </dt>
            <dd className="font-semibold text-ink">
              {subscription.cancel_at_period_end && until
                ? formatDate(until)
                : subscription.next_charge_at
                  ? formatDate(subscription.next_charge_at)
                  : t('subscription.none')}
            </dd>
          </div>
        </dl>
        <p className="mt-4">
          <StatusBadge subscription={subscription} />
        </p>
      </Card>

      <Card>
        <CardHeader title={t('subscription.payment')} />
        <p className="text-sm text-ink">
          {subscription.payment_method
            ? tf('subscription.paymentCard', {
                brand:
                  subscription.payment_method.brand ??
                  subscription.payment_method.type,
                last4: subscription.payment_method.last4 ?? '',
              })
            : t('subscription.paymentNone')}
        </p>
        {suspended ? (
          <p className="mt-2 text-sm text-muted">
            {t('subscription.regularizeHint')}
          </p>
        ) : null}
        {!canceled ? (
          <div className="mt-4">
            <Button
              variant="secondary"
              disabled={blocked}
              onClick={() => {
                setDialogError(null);
                setToken('');
                setPaymentOpen(true);
              }}
            >
              {t('subscription.change')}
            </Button>
          </div>
        ) : null}
      </Card>

      {!canceled ? (
        <Card>
          <CardHeader title={t('subscription.cancel')} />
          {subscription.cancel_at_period_end && until ? (
            <div className="space-y-3">
              <p className="text-sm text-ink">
                {tf('subscription.pending', { date: formatDate(until) })}
              </p>
              <Button
                variant="secondary"
                disabled={blocked}
                loading={busy}
                onClick={() =>
                  void act('subscription/reactivate', t('subscription.undone'))
                }
              >
                {t('subscription.undo')}
              </Button>
            </div>
          ) : (
            <Button
              variant="danger"
              disabled={blocked}
              onClick={() => setCancelOpen(true)}
            >
              {t('subscription.cancel')}
            </Button>
          )}
        </Card>
      ) : null}

      <section aria-labelledby="faturas">
        <h2 id="faturas" className="mb-3 text-lg font-semibold text-ink">
          {t('subscription.invoices')}
        </h2>
        {invoices.data.length === 0 ? (
          <p className="text-sm text-muted">{t('subscription.noInvoices')}</p>
        ) : (
          <>
            <Table caption={t('subscription.invoicesCaption')}>
              <thead>
                <Tr>
                  <Th>{t('subscription.period')}</Th>
                  <Th>{t('subscription.amount')}</Th>
                  <Th>{t('subscription.situation')}</Th>
                  <Th>{t('subscription.paidAt')}</Th>
                </Tr>
              </thead>
              <tbody>
                {invoices.data.map((invoice) => (
                  <Tr key={invoice.id}>
                    <Td>
                      {formatDate(invoice.period_start)} –{' '}
                      {formatDate(invoice.period_end)}
                    </Td>
                    <Td>{formatBRL(invoice.amount_cents)}</Td>
                    <Td>
                      <Badge
                        tone={invoice.status === 'paid' ? 'success' : 'danger'}
                      >
                        {t(
                          invoice.status === 'paid'
                            ? 'status.invoicePaid'
                            : 'status.invoiceFailed',
                        )}
                      </Badge>
                    </Td>
                    <Td>
                      {invoice.paid_at ? formatDate(invoice.paid_at) : '—'}
                    </Td>
                  </Tr>
                ))}
              </tbody>
            </Table>
            {invoices.next_cursor ? (
              <div className="mt-3">
                <Button
                  variant="secondary"
                  loading={loadingMore}
                  onClick={() => void loadMore()}
                >
                  {loadingMore
                    ? t('subscription.loadingMore')
                    : t('subscription.more')}
                </Button>
              </div>
            ) : null}
          </>
        )}
      </section>

      <Dialog
        open={paymentOpen}
        onClose={() => setPaymentOpen(false)}
        title={t('subscription.changeTitle')}
        description={suspended ? t('subscription.regularizeHint') : undefined}
        footer={
          <>
            <Button variant="secondary" onClick={() => setPaymentOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button
              loading={busy}
              disabled={!paymentIsFake()}
              onClick={() => void changePayment()}
            >
              {t('subscription.changeSubmit')}
            </Button>
          </>
        }
      >
        {dialogError ? (
          <Alert tone="danger" className="mb-3">
            {dialogError}
          </Alert>
        ) : null}
        {paymentIsFake() ? (
          <fieldset className="flex flex-col gap-3">
            <legend className="mb-2 text-sm text-muted">
              {t('subscription.testNotice')}
            </legend>
            {TEST_CARDS.map((card) => (
              <label
                key={card.token}
                className={cn(
                  'flex min-h-11 cursor-pointer items-center gap-3 rounded-md border p-3',
                  token === card.token
                    ? 'border-brand-600 bg-brand-50'
                    : 'border-control-border',
                )}
              >
                <input
                  type="radio"
                  name="payment"
                  value={card.token}
                  checked={token === card.token}
                  onChange={() => setToken(card.token)}
                  className="accent-brand-600"
                />
                {t(card.label)}
              </label>
            ))}
          </fieldset>
        ) : (
          <Alert tone="warning">{t('subscription.paymentUnavailable')}</Alert>
        )}
      </Dialog>

      <Dialog
        open={cancelOpen}
        onClose={() => setCancelOpen(false)}
        title={t('subscription.cancelTitle')}
        description={cancelBody}
        footer={
          <>
            <Button variant="secondary" onClick={() => setCancelOpen(false)}>
              {t('subscription.keep')}
            </Button>
            <Button
              variant="danger"
              loading={busy}
              onClick={() =>
                void act('subscription/cancel', t('subscription.canceled'))
              }
            >
              {t('subscription.cancelConfirm')}
            </Button>
          </>
        }
      />
    </div>
  );
}
