'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { Entitlement, TenantDetail } from '@/lib/admin-types';
import { formatBRL, formatDate } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog } from '@/components/ui/dialog';
import {
  CheckboxField,
  TextField,
  TextareaField,
} from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { unmatchedFieldError } from './list-utils';
import { TenantStatusBadge } from './tenant-status';

type DialogState =
  | { kind: 'suspend' }
  | { kind: 'unsuspend' }
  | { kind: 'extend' }
  | { kind: 'impersonate' }
  | { kind: 'entitlement'; entitlement: Entitlement }
  | { kind: 'reset'; entitlement: Entitlement };

const SOURCE: Record<Entitlement['source'], 'info' | 'neutral' | 'warning'> = {
  override: 'warning',
  plan: 'info',
  default: 'neutral',
};

/** Abre o painel do negócio com o acesso de suporte: o token vai só no fragmento (que não chega a nenhum servidor). */
function openSupport(url: string, token: string): boolean {
  const target = new URL(url);

  if (!['http:', 'https:'].includes(target.protocol)) {
    throw new Error('url');
  }

  target.pathname = '/suporte';
  target.hash = `token=${encodeURIComponent(token)}`;
  // Sem `noopener` o navegador devolve a janela (ou `null` se bloqueou o pop-up); o vínculo com esta página é cortado logo em seguida.
  const opened = window.open(target.toString(), '_blank');

  if (opened) {
    opened.opener = null;
  }

  return opened !== null;
}

/** Detalhe do assinante: situação, dona, cartão, faturas, funcionalidades e as ações da equipe. */
export function TenantDetailView({
  tenant,
  canWrite,
}: {
  tenant: TenantDetail;
  canWrite: boolean;
}) {
  const router = useRouter();
  const [dialog, setDialog] = useState<DialogState | null>(null);
  const [reason, setReason] = useState('');
  const [days, setDays] = useState('7');
  const [grant, setGrant] = useState({
    enabled: true,
    limit: '',
    visible: true,
  });
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);

  function open(next: DialogState) {
    setError(null);
    setFieldErrors({});
    setReason('');

    if (next.kind === 'entitlement') {
      setGrant({
        enabled: next.entitlement.enabled,
        limit:
          next.entitlement.limit === null ? '' : String(next.entitlement.limit),
        visible: next.entitlement.visible,
      });
    }

    setDialog(next);
  }

  async function run(work: () => Promise<unknown>, success: string) {
    setBusy(true);
    setError(null);
    setFieldErrors({});

    try {
      await work();
      setDialog(null);
      setNotice(success);
      router.refresh();
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 403) {
        setError(t('admin.noPermission'));
      } else if (
        failure instanceof ApiError &&
        Object.keys(failure.fieldErrors).length > 0
      ) {
        setFieldErrors(failure.fieldErrors);
        setError(
          unmatchedFieldError(failure.fieldErrors, ['reason', 'days', 'limit']),
        );
      } else {
        setError(
          failure instanceof ApiError
            ? failure.message
            : failure instanceof Error && failure.message === 'popup'
              ? t('admin.detail.popupBlocked')
              : t('common.genericError'),
        );
      }
    } finally {
      setBusy(false);
    }
  }

  function submit(event: FormEvent) {
    event.preventDefault();

    if (!dialog) return;

    const base = `platform/tenants/${tenant.id}`;

    switch (dialog.kind) {
      case 'suspend':
        void run(
          () =>
            call('POST', `${base}/suspend`, {
              body: { reason: reason.trim() },
            }),
          t('admin.detail.suspended'),
        );
        break;
      case 'unsuspend':
        void run(
          () => call('POST', `${base}/unsuspend`),
          t('admin.detail.unsuspended'),
        );
        break;
      case 'extend':
        void run(
          () =>
            call('POST', `${base}/extend-trial`, {
              body: { days: Number(days) },
            }),
          tf('admin.detail.extended', { days }),
        );
        break;
      case 'impersonate':
        void run(async () => {
          const { data } = await call<{ access_token: string; url: string }>(
            'POST',
            `${base}/impersonate`,
            { body: { reason: reason.trim() } },
          );
          // O token só passa por esta variável: vai ao fragmento da nova aba e não fica em estado nem na tela.
          if (!openSupport(data.url, data.access_token)) {
            throw new Error('popup');
          }
        }, t('admin.detail.supportOpened'));
        break;
      case 'entitlement':
        void run(
          () =>
            call('PUT', `${base}/entitlements/${dialog.entitlement.feature}`, {
              body: {
                enabled: grant.enabled,
                limit: grant.limit.trim() === '' ? null : Number(grant.limit),
                visible: grant.visible,
              },
            }),
          t('admin.detail.entitlementSaved'),
        );
        break;
      case 'reset':
        void run(
          () =>
            call(
              'DELETE',
              `${base}/entitlements/${dialog.entitlement.feature}`,
            ),
          t('admin.detail.entitlementRemoved'),
        );
        break;
    }
  }

  const suspended = tenant.status === 'suspended';
  const closable = tenant.status !== null && tenant.status !== 'canceled';

  return (
    <div className="space-y-6">
      <p>
        <Link href="/admin/assinantes" className="text-brand-700 underline">
          {t('admin.detail.back')}
        </Link>
      </p>
      <PageHeader
        title={tenant.name}
        description={tenant.slug}
        actions={
          <span className="flex flex-wrap gap-2">
            <Button
              variant="secondary"
              onClick={() => open({ kind: 'impersonate' })}
            >
              {t('admin.detail.impersonate')}
            </Button>
            {canWrite && closable && !suspended ? (
              <Button
                variant="danger"
                onClick={() => open({ kind: 'suspend' })}
              >
                {t('admin.detail.suspend')}
              </Button>
            ) : null}
            {canWrite && suspended ? (
              <Button onClick={() => open({ kind: 'unsuspend' })}>
                {t('admin.detail.unsuspend')}
              </Button>
            ) : null}
            {canWrite && tenant.status === 'trialing' ? (
              <Button
                variant="secondary"
                onClick={() => open({ kind: 'extend' })}
              >
                {t('admin.detail.extend')}
              </Button>
            ) : null}
          </span>
        }
      />
      {notice ? <Alert tone="success">{notice}</Alert> : null}
      {!canWrite ? <Alert tone="info">{t('admin.readOnly')}</Alert> : null}
      {suspended && tenant.suspended_reason ? (
        <Alert tone="warning">
          {tf('admin.detail.suspendedReason', {
            reason: tenant.suspended_reason,
          })}
        </Alert>
      ) : null}

      <div className="grid gap-4 md:grid-cols-2">
        <Card>
          <h2 className="text-lg font-semibold text-ink">
            {t('admin.detail.subscription')}
          </h2>
          <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
            <dt className="text-muted">{t('admin.tenants.situation')}</dt>
            <dd>
              <TenantStatusBadge tenant={tenant} />
            </dd>
            <dt className="text-muted">{t('admin.tenants.plan')}</dt>
            <dd>
              {tenant.plan ?? '—'}
              {tenant.cycle ? ` · ${t(`admin.cycle.${tenant.cycle}`)}` : ''}
            </dd>
            <dt className="text-muted">{t('admin.tenants.price')}</dt>
            <dd>
              {tenant.price_cents === null
                ? '—'
                : formatBRL(tenant.price_cents)}
            </dd>
            <dt className="text-muted">{t('admin.detail.trialEnds')}</dt>
            <dd>
              {tenant.trial_ends_at ? formatDate(tenant.trial_ends_at) : '—'}
            </dd>
            <dt className="text-muted">{t('admin.detail.periodEnd')}</dt>
            <dd>
              {tenant.current_period_end
                ? formatDate(tenant.current_period_end)
                : '—'}
            </dd>
            <dt className="text-muted">{t('admin.detail.coupon')}</dt>
            <dd>
              {tenant.coupon
                ? tf('admin.detail.couponValue', {
                    code: tenant.coupon.code,
                    percent: tenant.coupon.percent_off,
                  })
                : '—'}
            </dd>
          </dl>
        </Card>
        <Card>
          <h2 className="text-lg font-semibold text-ink">
            {t('admin.detail.business')}
          </h2>
          <dl className="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
            <dt className="text-muted">{t('admin.detail.owner')}</dt>
            <dd>{tenant.owner ? tenant.owner.name : '—'}</dd>
            <dt className="text-muted">{t('admin.detail.ownerEmail')}</dt>
            <dd>{tenant.owner ? tenant.owner.email : '—'}</dd>
            <dt className="text-muted">{t('admin.detail.people')}</dt>
            <dd>{tenant.active_people}</dd>
            <dt className="text-muted">{t('admin.detail.card')}</dt>
            <dd>
              {tenant.payment_method
                ? `${tenant.payment_method.brand ?? tenant.payment_method.type} •••• ${tenant.payment_method.last4 ?? ''}`
                : t('admin.detail.noCard')}
            </dd>
            <dt className="text-muted">{t('admin.detail.createdAt')}</dt>
            <dd>{formatDate(tenant.created_at)}</dd>
          </dl>
        </Card>
      </div>

      <div>
        <h2 className="mb-3 text-lg font-semibold text-ink">
          {t('admin.detail.entitlements')}
        </h2>
        <Table caption={t('admin.detail.entitlementsCaption')}>
          <thead>
            <Tr>
              <Th>{t('admin.detail.feature')}</Th>
              <Th>{t('admin.detail.enabled')}</Th>
              <Th>{t('admin.detail.limit')}</Th>
              <Th>{t('admin.detail.source')}</Th>
              {canWrite ? <Th>{t('admin.actions')}</Th> : null}
            </Tr>
          </thead>
          <tbody>
            {tenant.entitlements.map((entitlement) => (
              <Tr key={entitlement.feature}>
                <Td>{entitlement.feature}</Td>
                <Td>{entitlement.enabled ? t('admin.yes') : t('admin.no')}</Td>
                <Td>{entitlement.limit ?? '—'}</Td>
                <Td>
                  <Badge tone={SOURCE[entitlement.source]}>
                    {t(`admin.source.${entitlement.source}`)}
                  </Badge>
                </Td>
                {canWrite ? (
                  <Td>
                    <span className="flex flex-wrap gap-2">
                      <Button
                        variant="secondary"
                        onClick={() =>
                          open({ kind: 'entitlement', entitlement })
                        }
                        aria-label={`${t('admin.detail.setException')}: ${entitlement.feature}`}
                      >
                        {t('admin.detail.setException')}
                      </Button>
                      {entitlement.source === 'override' ? (
                        <Button
                          variant="secondary"
                          onClick={() => open({ kind: 'reset', entitlement })}
                          aria-label={`${t('admin.detail.removeException')}: ${entitlement.feature}`}
                        >
                          {t('admin.detail.removeException')}
                        </Button>
                      ) : null}
                    </span>
                  </Td>
                ) : null}
              </Tr>
            ))}
          </tbody>
        </Table>
      </div>

      <div>
        <h2 className="mb-3 text-lg font-semibold text-ink">
          {t('admin.detail.invoices')}
        </h2>
        <Table caption={t('admin.detail.invoicesCaption')}>
          <thead>
            <Tr>
              <Th>{t('admin.detail.period')}</Th>
              <Th>{t('admin.tenants.price')}</Th>
              <Th>{t('admin.tenants.situation')}</Th>
              <Th>{t('admin.detail.paidAt')}</Th>
            </Tr>
          </thead>
          <tbody>
            {tenant.invoices.map((invoice) => (
              <Tr key={invoice.id}>
                <Td>{`${formatDate(invoice.period_start)} – ${formatDate(invoice.period_end)}`}</Td>
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
                <Td>{invoice.paid_at ? formatDate(invoice.paid_at) : '—'}</Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      </div>

      <Dialog
        open={dialog !== null}
        onClose={() => setDialog(null)}
        title={dialog ? t(`admin.dialog.${dialog.kind}.title`) : ''}
        description={dialog ? t(`admin.dialog.${dialog.kind}.text`) : undefined}
      >
        <form onSubmit={submit} noValidate className="flex flex-col gap-4">
          {error ? <Alert tone="danger">{error}</Alert> : null}
          {dialog?.kind === 'suspend' || dialog?.kind === 'impersonate' ? (
            <TextareaField
              label={t('admin.dialog.reason')}
              hint={t(
                dialog.kind === 'suspend'
                  ? 'admin.dialog.reasonHintSuspend'
                  : 'admin.dialog.reasonHintSupport',
              )}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              error={fieldErrors.reason}
              maxLength={200}
              required
            />
          ) : null}
          {dialog?.kind === 'extend' ? (
            <TextField
              label={t('admin.dialog.days')}
              hint={t('admin.dialog.daysHint')}
              type="number"
              inputMode="numeric"
              min={1}
              max={30}
              value={days}
              onChange={(event) => setDays(event.target.value)}
              error={fieldErrors.days}
              required
            />
          ) : null}
          {dialog?.kind === 'entitlement' ? (
            <>
              <CheckboxField
                label={t('admin.dialog.enabled')}
                checked={grant.enabled}
                onChange={(event) =>
                  setGrant({ ...grant, enabled: event.target.checked })
                }
              />
              <TextField
                label={t('admin.dialog.limit')}
                hint={t('admin.dialog.limitHint')}
                type="number"
                inputMode="numeric"
                min={0}
                value={grant.limit}
                onChange={(event) =>
                  setGrant({ ...grant, limit: event.target.value })
                }
                error={fieldErrors.limit}
              />
              <CheckboxField
                label={t('admin.dialog.visible')}
                checked={grant.visible}
                onChange={(event) =>
                  setGrant({ ...grant, visible: event.target.checked })
                }
              />
            </>
          ) : null}
          <div className="flex flex-wrap justify-end gap-2">
            <Button
              variant="secondary"
              onClick={() => setDialog(null)}
              disabled={busy}
            >
              {t('common.cancel')}
            </Button>
            <Button
              type="submit"
              variant={
                dialog?.kind === 'suspend' || dialog?.kind === 'reset'
                  ? 'danger'
                  : 'primary'
              }
              loading={busy}
            >
              {t('admin.dialog.confirm')}
            </Button>
          </div>
        </form>
      </Dialog>
    </div>
  );
}
