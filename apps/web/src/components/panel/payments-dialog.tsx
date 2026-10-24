'use client';

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FormEvent,
} from 'react';
import { t, tf, type MessageKey } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type {
  Appointment,
  Payment,
  PaymentList,
} from '@/lib/appointment-types';
import { formatDateTime } from '@/lib/audit';
import { formatBRL } from '@/lib/format';
import { reaisToCents } from '@/lib/money';
import { fromLocalInput } from '@/lib/professional-types';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { SelectField, TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';

const TYPES = ['deposit', 'installment', 'final'] as const;
const METHODS = ['pix', 'cash', 'credit_card', 'debit_card', 'other'] as const;

/** Pagamentos de um agendamento (FIN-001): resumo, lista com estorno e novo pagamento. Dona e gerente. */
export function PaymentsDialog({
  appointment,
  canWrite,
  onClose,
  onChanged,
}: {
  appointment: Appointment;
  canWrite: boolean;
  onClose: () => void;
  /** Avisa a agenda para recarregar a situação do pagamento. */
  onChanged: () => void;
}) {
  const [data, setData] = useState<PaymentList | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{
    tone: 'success' | 'danger';
    text: string;
  } | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [confirmVoid, setConfirmVoid] = useState<string | null>(null);
  const [type, setType] = useState<string>('deposit');
  const [method, setMethod] = useState<string>('pix');
  const [amount, setAmount] = useState('');
  const [paidAt, setPaidAt] = useState('');
  // Uma chave por tentativa: repetir o envio (rede ruim) não duplica o pagamento; muda depois de cada sucesso.
  const key = useRef(crypto.randomUUID());
  const appointmentId = appointment.id;

  const load = useCallback(() => {
    call<PaymentList>('GET', `appointments/${appointmentId}/payments`)
      .then(({ data: list }) => {
        setData(list);
        setError(null);
      })
      .catch(() => {
        setError(t('payments.loadError'));
      });
  }, [appointmentId]);

  // Carrega ao abrir (o diálogo só existe enquanto está aberto).
  useEffect(() => {
    load();
  }, [load]);

  function explain(failure: unknown): string {
    if (failure instanceof ApiError) {
      if (failure.code === 'payment_exceeds_total') {
        return tf('payments.exceeds', {
          max: formatBRL(data?.summary.remaining_cents ?? 0),
        });
      }

      if (failure.code === 'appointment_closed') return t('payments.closed');

      if (failure.code === 'appointment_without_value')
        return t('payments.noValue');

      return failure.message;
    }

    return t('common.genericError');
  }

  async function add(event: FormEvent) {
    event.preventDefault();

    const cents = reaisToCents(amount);

    if (cents === null || Number.isNaN(cents) || cents < 1) {
      setFieldErrors({
        amount_cents: tf('payments.amountHint', {
          max: formatBRL(data?.summary.remaining_cents ?? 0),
        }),
      });

      return;
    }

    setSaving(true);
    setFieldErrors({});
    setNotice(null);

    try {
      await call('POST', `appointments/${appointment.id}/payments`, {
        body: {
          type,
          method,
          amount_cents: cents,
          ...(paidAt ? { paid_at: fromLocalInput(paidAt) } : {}),
        },
        idempotencyKey: key.current,
      });
      key.current = crypto.randomUUID();
      setAmount('');
      setPaidAt('');
      setNotice({ tone: 'success', text: t('payments.added') });
      load();
      onChanged();
    } catch (failure) {
      if (
        failure instanceof ApiError &&
        failure.code === 'payment_exceeds_total'
      ) {
        setFieldErrors({ amount_cents: explain(failure) });
        load();
      } else if (
        failure instanceof ApiError &&
        Object.keys(failure.fieldErrors).length > 0
      ) {
        setFieldErrors(failure.fieldErrors);
      } else {
        setNotice({ tone: 'danger', text: explain(failure) });
      }
    } finally {
      setSaving(false);
    }
  }

  async function voidPayment(payment: Payment) {
    setSaving(true);
    setNotice(null);

    try {
      await call('POST', `payments/${payment.id}/void`);
      setConfirmVoid(null);
      setNotice({ tone: 'success', text: t('payments.voided') });
      load();
      onChanged();
    } catch (failure) {
      setNotice({ tone: 'danger', text: explain(failure) });
    } finally {
      setSaving(false);
    }
  }

  const summary = data?.summary;

  return (
    <Dialog
      open
      onClose={onClose}
      title={tf('payments.title', { client: appointment.client.name })}
      description={t('payments.description')}
      footer={
        <Button variant="secondary" onClick={onClose}>
          {t('payments.close')}
        </Button>
      }
    >
      <div className="space-y-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}
        {!canWrite ? (
          <Alert tone="warning">{t('payments.readOnly')}</Alert>
        ) : null}
        {!data && !error ? (
          <p className="text-sm text-muted">{t('payments.loading')}</p>
        ) : null}

        {summary ? (
          <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div>
              <dt className="text-muted">{t('payments.combined')}</dt>
              <dd className="font-semibold text-ink">
                {formatBRL(summary.total_cents)}
              </dd>
            </div>
            <div>
              <dt className="text-muted">{t('payments.received')}</dt>
              <dd className="font-semibold text-ink">
                {formatBRL(summary.received_cents)}
              </dd>
            </div>
            <div>
              <dt className="text-muted">{t('payments.remaining')}</dt>
              <dd className="font-semibold text-ink">
                {formatBRL(summary.remaining_cents)}
              </dd>
            </div>
            <div>
              <dt className="text-muted">{t('payments.commission')}</dt>
              <dd className="font-semibold text-ink">
                {tf('payments.commissionValue', {
                  percent: summary.commission_percent,
                  value: formatBRL(summary.commission_cents),
                })}
              </dd>
            </div>
          </dl>
        ) : null}

        {data && data.data.length === 0 ? (
          <p className="text-sm text-muted">{t('payments.empty')}</p>
        ) : null}
        {data && data.data.length > 0 ? (
          <Table caption={t('payments.caption')}>
            <thead>
              <Tr>
                <Th>{t('payments.date')}</Th>
                <Th>{t('payments.type')}</Th>
                <Th>{t('payments.method')}</Th>
                <Th>{t('payments.amount')}</Th>
                <Th>{t('payments.status')}</Th>
                <Th>{t('payments.actions')}</Th>
              </Tr>
            </thead>
            <tbody>
              {data.data.map((payment) => (
                <Tr key={payment.id}>
                  <Td>{formatDateTime(payment.paid_at)}</Td>
                  <Td>{t(`payments.type_${payment.type}` as MessageKey)}</Td>
                  <Td>
                    {t(`payments.method_${payment.method}` as MessageKey)}
                  </Td>
                  <Td>{formatBRL(payment.amount_cents)}</Td>
                  <Td>
                    <Badge
                      tone={
                        payment.status === 'received' ? 'success' : 'neutral'
                      }
                    >
                      {t(`payments.status_${payment.status}` as MessageKey)}
                    </Badge>
                  </Td>
                  <Td>
                    {payment.status === 'received' && canWrite ? (
                      confirmVoid === payment.id ? (
                        <span className="flex flex-wrap gap-2">
                          <Button
                            variant="secondary"
                            loading={saving}
                            onClick={() => void voidPayment(payment)}
                          >
                            {t('payments.voidConfirm')}
                          </Button>
                          <Button
                            variant="secondary"
                            onClick={() => setConfirmVoid(null)}
                          >
                            {t('payments.voidKeep')}
                          </Button>
                        </span>
                      ) : (
                        <Button
                          variant="secondary"
                          onClick={() => setConfirmVoid(payment.id)}
                          aria-label={`${t('payments.void')}: ${formatBRL(payment.amount_cents)}`}
                        >
                          {t('payments.void')}
                        </Button>
                      )
                    ) : null}
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        ) : null}

        {canWrite && summary && summary.total_cents <= 0 ? (
          <Alert tone="info">{t('payments.noValue')}</Alert>
        ) : null}
        {canWrite && summary && summary.total_cents > 0 ? (
          <form className="space-y-3" onSubmit={(event) => void add(event)}>
            <h3 className="text-base font-semibold text-ink">
              {t('payments.newTitle')}
            </h3>
            <div className="grid gap-3 sm:grid-cols-2">
              <SelectField
                label={t('payments.typeField')}
                value={type}
                onChange={(event) => setType(event.target.value)}
              >
                {TYPES.map((value) => (
                  <option key={value} value={value}>
                    {t(`payments.type_${value}` as MessageKey)}
                  </option>
                ))}
              </SelectField>
              <SelectField
                label={t('payments.methodField')}
                value={method}
                onChange={(event) => setMethod(event.target.value)}
              >
                {METHODS.map((value) => (
                  <option key={value} value={value}>
                    {t(`payments.method_${value}` as MessageKey)}
                  </option>
                ))}
              </SelectField>
              <TextField
                label={t('payments.amountField')}
                hint={tf('payments.amountHint', {
                  max: formatBRL(summary.remaining_cents),
                })}
                inputMode="decimal"
                value={amount}
                error={fieldErrors.amount_cents}
                onChange={(event) => setAmount(event.target.value)}
              />
              <TextField
                label={t('payments.paidAtField')}
                hint={t('payments.paidAtHint')}
                type="datetime-local"
                value={paidAt}
                error={fieldErrors.paid_at}
                onChange={(event) => setPaidAt(event.target.value)}
              />
            </div>
            <Button
              type="submit"
              loading={saving}
              disabled={summary.remaining_cents <= 0}
            >
              {t('payments.add')}
            </Button>
          </form>
        ) : null}
      </div>
    </Dialog>
  );
}
