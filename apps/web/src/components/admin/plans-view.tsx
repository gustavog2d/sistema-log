'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { Plan, PlanStatus } from '@/lib/admin-types';
import { formatBRL } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge, type Tone } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { centsToReais, reaisToCents } from './list-utils';

const TONES: Record<PlanStatus, Tone> = {
  active: 'success',
  draft: 'warning',
  archived: 'neutral',
};

type Form = {
  name: string;
  position: string;
  status: PlanStatus;
  trial: string;
  price: string;
  discount: string;
};

/** Planos e preços: leitura para a equipe; o super admin edita (a chave e a moeda não mudam). */
export function PlansView({
  plans,
  canWrite,
}: {
  plans: Plan[] | null;
  canWrite: boolean;
}) {
  const router = useRouter();
  const [editing, setEditing] = useState<Plan | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);

  function open(plan: Plan) {
    setError(null);
    setFieldErrors({});
    setForm({
      name: plan.name,
      position: String(plan.position),
      status: plan.status,
      trial: String(plan.trial_days),
      price: centsToReais(plan.monthly_price_cents),
      discount:
        plan.yearly_discount_percent === null
          ? ''
          : String(plan.yearly_discount_percent),
    });
    setEditing(plan);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    if (!editing || !form) return;

    const cents = reaisToCents(form.price);

    if (Number.isNaN(cents)) {
      setFieldErrors({ monthly_price_cents: t('admin.plans.priceInvalid') });

      return;
    }

    setBusy(true);
    setError(null);
    setFieldErrors({});

    try {
      await call('PATCH', `platform/plans/${editing.key}`, {
        body: {
          name: form.name.trim(),
          position: Number(form.position),
          status: form.status,
          trial_days: Number(form.trial),
          monthly_price_cents: cents,
          yearly_discount_percent:
            form.discount.trim() === '' ? null : Number(form.discount),
        },
      });
      setEditing(null);
      setNotice(t('admin.plans.saved'));
      router.refresh();
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 403) {
        setError(t('admin.noPermission'));
      } else if (
        failure instanceof ApiError &&
        Object.keys(failure.fieldErrors).length > 0
      ) {
        setFieldErrors(failure.fieldErrors);
      } else {
        setError(
          failure instanceof ApiError
            ? failure.message
            : t('common.genericError'),
        );
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('admin.plans.title')}
        description={t('admin.plans.text')}
      />
      {notice ? <Alert tone="success">{notice}</Alert> : null}
      {!canWrite ? <Alert tone="info">{t('admin.readOnly')}</Alert> : null}
      {plans === null ? (
        <Alert tone="danger">{t('admin.loadError')}</Alert>
      ) : null}
      {plans && plans.length === 0 ? (
        <EmptyState title={t('admin.plans.empty')} />
      ) : null}
      {plans && plans.length > 0 ? (
        <Table caption={t('admin.plans.caption')}>
          <thead>
            <Tr>
              <Th>{t('admin.plans.name')}</Th>
              <Th>{t('admin.plans.key')}</Th>
              <Th>{t('admin.tenants.situation')}</Th>
              <Th>{t('admin.plans.trial')}</Th>
              <Th>{t('admin.plans.monthly')}</Th>
              <Th>{t('admin.plans.discount')}</Th>
              <Th>{t('admin.plans.yearly')}</Th>
              {canWrite ? <Th>{t('admin.actions')}</Th> : null}
            </Tr>
          </thead>
          <tbody>
            {plans.map((plan) => (
              <Tr key={plan.id}>
                <Td>{plan.name}</Td>
                <Td>{plan.key}</Td>
                <Td>
                  <Badge tone={TONES[plan.status]}>
                    {t(`admin.plans.${plan.status}`)}
                  </Badge>
                </Td>
                <Td>{plan.trial_days}</Td>
                <Td>
                  {plan.monthly_price_cents === null
                    ? '—'
                    : formatBRL(plan.monthly_price_cents)}
                </Td>
                <Td>
                  {plan.yearly_discount_percent === null
                    ? '—'
                    : `${plan.yearly_discount_percent}%`}
                </Td>
                <Td>
                  {plan.yearly_price_cents === null
                    ? '—'
                    : formatBRL(plan.yearly_price_cents)}
                </Td>
                {canWrite ? (
                  <Td>
                    <Button
                      variant="secondary"
                      onClick={() => open(plan)}
                      aria-label={`${t('admin.plans.edit')}: ${plan.name}`}
                    >
                      {t('admin.plans.edit')}
                    </Button>
                  </Td>
                ) : null}
              </Tr>
            ))}
          </tbody>
        </Table>
      ) : null}

      <Dialog
        open={editing !== null}
        onClose={() => setEditing(null)}
        title={t('admin.plans.editTitle')}
        description={t('admin.plans.dialogText')}
      >
        {form ? (
          <form
            onSubmit={(event) => void submit(event)}
            noValidate
            className="flex flex-col gap-4"
          >
            {error ? <Alert tone="danger">{error}</Alert> : null}
            <TextField
              label={t('admin.plans.name')}
              value={form.name}
              onChange={(event) =>
                setForm({ ...form, name: event.target.value })
              }
              error={fieldErrors.name}
              maxLength={100}
              required
            />
            <SelectField
              label={t('admin.tenants.situation')}
              value={form.status}
              onChange={(event) =>
                setForm({ ...form, status: event.target.value as PlanStatus })
              }
              error={fieldErrors.status}
            >
              {(['draft', 'active', 'archived'] as const).map((value) => (
                <option key={value} value={value}>
                  {t(`admin.plans.${value}`)}
                </option>
              ))}
            </SelectField>
            <TextField
              label={t('admin.plans.monthly')}
              hint={t('admin.plans.priceHint')}
              inputMode="decimal"
              value={form.price}
              onChange={(event) =>
                setForm({ ...form, price: event.target.value })
              }
              error={fieldErrors.monthly_price_cents}
            />
            <TextField
              label={t('admin.plans.discount')}
              type="number"
              inputMode="numeric"
              min={0}
              max={100}
              value={form.discount}
              onChange={(event) =>
                setForm({ ...form, discount: event.target.value })
              }
              error={fieldErrors.yearly_discount_percent}
            />
            <TextField
              label={t('admin.plans.trial')}
              type="number"
              inputMode="numeric"
              min={0}
              max={365}
              value={form.trial}
              onChange={(event) =>
                setForm({ ...form, trial: event.target.value })
              }
              error={fieldErrors.trial_days}
              required
            />
            <TextField
              label={t('admin.plans.position')}
              type="number"
              inputMode="numeric"
              min={0}
              value={form.position}
              onChange={(event) =>
                setForm({ ...form, position: event.target.value })
              }
              error={fieldErrors.position}
              required
            />
            <div className="flex flex-wrap justify-end gap-2">
              <Button
                variant="secondary"
                onClick={() => setEditing(null)}
                disabled={busy}
              >
                {t('common.cancel')}
              </Button>
              <Button type="submit" loading={busy}>
                {t('admin.dialog.save')}
              </Button>
            </div>
          </form>
        ) : null}
      </Dialog>
    </div>
  );
}
