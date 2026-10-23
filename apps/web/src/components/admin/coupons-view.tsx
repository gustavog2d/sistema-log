'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { Coupon } from '@/lib/admin-types';
import { formatDate } from '@/lib/format';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { fromDateInput, toDateInput, unmatchedFieldError } from './list-utils';

type Form = {
  code: string;
  percent: string;
  max: string;
  from: string;
  until: string;
  description: string;
  status: 'active' | 'inactive';
};

const EMPTY: Form = {
  code: '',
  percent: '',
  max: '',
  from: '',
  until: '',
  description: '',
  status: 'active',
};

/** Cupons: lista para a equipe; criar e alterar só para o super admin. */
export function CouponsView({
  coupons,
  canWrite,
}: {
  coupons: Coupon[] | null;
  canWrite: boolean;
}) {
  const router = useRouter();
  const [editing, setEditing] = useState<Coupon | 'new' | null>(null);
  const [form, setForm] = useState<Form>(EMPTY);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<string | null>(null);

  function open(target: Coupon | 'new') {
    setError(null);
    setFieldErrors({});
    setForm(
      target === 'new'
        ? EMPTY
        : {
            code: target.code,
            percent: String(target.percent_off),
            max:
              target.max_redemptions === null
                ? ''
                : String(target.max_redemptions),
            from: toDateInput(target.valid_from),
            until: toDateInput(target.valid_until),
            description: target.description ?? '',
            status: target.status,
          },
    );
    setEditing(target);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    if (!editing) return;

    const max = form.max.trim() === '' ? null : Number(form.max);
    const body = {
      max_redemptions: max,
      valid_from: fromDateInput(form.from, 'start'),
      valid_until: fromDateInput(form.until, 'end'),
      description:
        form.description.trim() === '' ? null : form.description.trim(),
    };

    setBusy(true);
    setError(null);
    setFieldErrors({});

    try {
      if (editing === 'new') {
        await call('POST', 'platform/coupons', {
          body: {
            code: form.code.trim(),
            percent_off: Number(form.percent),
            ...body,
          },
        });
        setNotice(t('admin.coupons.created'));
      } else {
        await call('PATCH', `platform/coupons/${editing.id}`, {
          body: { status: form.status, ...body },
        });
        setNotice(t('admin.coupons.saved'));
      }

      setEditing(null);
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
          unmatchedFieldError(failure.fieldErrors, [
            'code',
            'percent_off',
            'status',
            'max_redemptions',
            'valid_from',
            'valid_until',
            'description',
          ]),
        );
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

  const courtesy =
    editing === 'new'
      ? Number(form.percent) === 100
      : editing?.percent_off === 100;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('admin.coupons.title')}
        description={t('admin.coupons.text')}
        actions={
          canWrite ? (
            <Button onClick={() => open('new')}>
              {t('admin.coupons.create')}
            </Button>
          ) : null
        }
      />
      {notice ? <Alert tone="success">{notice}</Alert> : null}
      {!canWrite ? <Alert tone="info">{t('admin.readOnly')}</Alert> : null}
      {coupons === null ? (
        <Alert tone="danger">{t('admin.loadError')}</Alert>
      ) : null}

      {coupons && coupons.length === 0 ? (
        <EmptyState title={t('admin.coupons.empty')} />
      ) : null}
      {coupons && coupons.length > 0 ? (
        <Table caption={t('admin.coupons.caption')}>
          <thead>
            <Tr>
              <Th>{t('admin.coupons.code')}</Th>
              <Th>{t('admin.coupons.percent')}</Th>
              <Th>{t('admin.tenants.situation')}</Th>
              <Th>{t('admin.coupons.uses')}</Th>
              <Th>{t('admin.coupons.validity')}</Th>
              {canWrite ? <Th>{t('admin.actions')}</Th> : null}
            </Tr>
          </thead>
          <tbody>
            {coupons.map((coupon) => (
              <Tr key={coupon.id}>
                <Td>{coupon.code}</Td>
                <Td>
                  {coupon.percent_off}%{' '}
                  {coupon.percent_off === 100 ? (
                    <Badge tone="warning">{t('admin.coupons.courtesy')}</Badge>
                  ) : null}
                </Td>
                <Td>
                  <Badge
                    tone={coupon.status === 'active' ? 'success' : 'neutral'}
                  >
                    {t(`admin.coupons.${coupon.status}`)}
                  </Badge>
                </Td>
                <Td>
                  {coupon.max_redemptions === null
                    ? String(coupon.redeemed_count)
                    : `${coupon.redeemed_count} / ${coupon.max_redemptions}`}
                </Td>
                <Td>
                  {coupon.valid_from || coupon.valid_until
                    ? `${coupon.valid_from ? formatDate(coupon.valid_from) : '…'} – ${coupon.valid_until ? formatDate(coupon.valid_until) : '…'}`
                    : t('admin.coupons.always')}
                </Td>
                {canWrite ? (
                  <Td>
                    <Button
                      variant="secondary"
                      onClick={() => open(coupon)}
                      aria-label={`${t('admin.coupons.edit')}: ${coupon.code}`}
                    >
                      {t('admin.coupons.edit')}
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
        title={t(
          editing === 'new'
            ? 'admin.coupons.createTitle'
            : 'admin.coupons.editTitle',
        )}
        description={t('admin.coupons.dialogText')}
      >
        <form
          onSubmit={(event) => void submit(event)}
          noValidate
          className="flex flex-col gap-4"
        >
          {error ? <Alert tone="danger">{error}</Alert> : null}
          {courtesy ? (
            <Alert tone="warning">{t('admin.coupons.courtesyWarning')}</Alert>
          ) : null}
          <TextField
            label={t('admin.coupons.code')}
            value={form.code}
            onChange={(event) => setForm({ ...form, code: event.target.value })}
            error={fieldErrors.code}
            disabled={editing !== 'new'}
            maxLength={40}
            autoComplete="off"
            required
          />
          <TextField
            label={t('admin.coupons.percent')}
            type="number"
            inputMode="numeric"
            min={1}
            max={100}
            value={form.percent}
            onChange={(event) =>
              setForm({ ...form, percent: event.target.value })
            }
            error={fieldErrors.percent_off}
            disabled={editing !== 'new'}
            required
          />
          {editing !== 'new' ? (
            <SelectField
              label={t('admin.tenants.situation')}
              value={form.status}
              onChange={(event) =>
                setForm({
                  ...form,
                  status: event.target.value as Form['status'],
                })
              }
              error={fieldErrors.status}
            >
              <option value="active">{t('admin.coupons.active')}</option>
              <option value="inactive">{t('admin.coupons.inactive')}</option>
            </SelectField>
          ) : null}
          <TextField
            label={t('admin.coupons.max')}
            hint={t('admin.coupons.maxHint')}
            type="number"
            inputMode="numeric"
            min={1}
            value={form.max}
            onChange={(event) => setForm({ ...form, max: event.target.value })}
            error={fieldErrors.max_redemptions}
          />
          <TextField
            label={t('admin.coupons.from')}
            type="date"
            value={form.from}
            onChange={(event) => setForm({ ...form, from: event.target.value })}
            error={fieldErrors.valid_from}
          />
          <TextField
            label={t('admin.coupons.until')}
            type="date"
            value={form.until}
            onChange={(event) =>
              setForm({ ...form, until: event.target.value })
            }
            error={fieldErrors.valid_until}
          />
          <TextField
            label={t('admin.coupons.description')}
            value={form.description}
            onChange={(event) =>
              setForm({ ...form, description: event.target.value })
            }
            error={fieldErrors.description}
            maxLength={200}
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
      </Dialog>
    </div>
  );
}
