'use client';

import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import {
  addMonths,
  formatDay,
  monthRange,
  monthTitle,
  type Expense,
  type ExpenseCategory,
  type ExpenseList,
} from '@/lib/expense-types';
import { formatBRL } from '@/lib/format';
import { centsToReais, reaisToCents } from '@/lib/money';
import type { Me } from '@/lib/panel-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';
import { ExpenseCategoriesDialog } from './expense-categories-dialog';

type Form = {
  categoryId: string;
  description: string;
  amount: string;
  spentOn: string;
};

type Dialogs =
  | { kind: 'form'; expense: Expense | null }
  | { kind: 'delete'; expense: Expense }
  | null;

/** Despesas do mês (FIN-002): totais por categoria, lista, nova, editar, excluir e categorias. Dona e gerente. */
export function ExpensesView({
  me,
  initial,
  categories: initialCategories,
  month: initialMonth,
}: {
  me: Me;
  initial: ExpenseList | null;
  categories: ExpenseCategory[];
  /** Mês inicial (`aaaa-mm`), calculado no servidor. */
  month: string;
}) {
  const [month, setMonth] = useState(initialMonth);
  const [list, setList] = useState<ExpenseList | null>(initial);
  const [categories, setCategories] = useState(initialCategories);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(
    initial ? null : t('expenses.loadError'),
  );
  const [notice, setNotice] = useState<{
    tone: 'success' | 'danger';
    text: string;
  } | null>(null);
  const [dialog, setDialog] = useState<Dialogs>(null);
  const [categoriesOpen, setCategoriesOpen] = useState(false);
  const [form, setForm] = useState<Form>({
    categoryId: '',
    description: '',
    amount: '',
    spentOn: '',
  });
  const [saving, setSaving] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const canWrite = me.can_write && !me.impersonating;

  function explain(failure: unknown): string {
    if (failure instanceof ApiError && failure.status === 403) {
      return failure.code === 'impersonation_forbidden'
        ? t('expenses.supportReadOnly')
        : t('panel.noPermission');
    }

    return failure instanceof ApiError
      ? failure.message
      : t('common.genericError');
  }

  function load(nextMonth: string) {
    const range = monthRange(nextMonth);

    setLoading(true);
    setError(null);

    call<ExpenseList>(
      'GET',
      `expenses?from=${range.from}&to=${range.to}&limit=500`,
    )
      .then(({ data }) => {
        setList(data);
      })
      .catch(() => {
        setError(t('expenses.loadError'));
      })
      .finally(() => {
        setLoading(false);
      });
  }

  function go(nextMonth: string) {
    setMonth(nextMonth);
    load(nextMonth);
  }

  function open(next: Dialogs) {
    setDialogError(null);
    setFieldErrors({});

    if (next?.kind === 'form') {
      const expense = next.expense;

      setForm(
        expense
          ? {
              categoryId: expense.category.id,
              description: expense.description,
              amount: centsToReais(expense.amount_cents),
              spentOn: expense.spent_on,
            }
          : {
              categoryId: categories[0]?.id ?? '',
              description: '',
              amount: '',
              spentOn: `${month}-01`,
            },
      );
    }

    setDialog(next);
  }

  async function submit(event?: FormEvent) {
    event?.preventDefault();

    if (dialog?.kind !== 'form') return;

    const target = dialog.expense;
    const cents = reaisToCents(form.amount);

    if (cents === null || Number.isNaN(cents) || cents < 1) {
      setFieldErrors({ amount_cents: t('expenses.amountField') });

      return;
    }

    const body = {
      category_id: form.categoryId,
      description: form.description.trim(),
      amount_cents: cents,
      spent_on: form.spentOn,
    };

    setSaving(true);
    setDialogError(null);
    setFieldErrors({});

    try {
      await (target
        ? call<Expense>('PATCH', `expenses/${target.id}`, { body })
        : call<Expense>('POST', 'expenses', { body }));
      setDialog(null);
      load(month);
      setNotice({
        tone: 'success',
        text: t(target ? 'expenses.saved' : 'expenses.created'),
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

  async function remove() {
    if (dialog?.kind !== 'delete') return;

    setSaving(true);
    setDialogError(null);

    try {
      await call('DELETE', `expenses/${dialog.expense.id}`);
      setDialog(null);
      load(month);
      setNotice({ tone: 'success', text: t('expenses.deleted') });
    } catch (failure) {
      setDialogError(explain(failure));
    } finally {
      setSaving(false);
    }
  }

  const deleting = dialog?.kind === 'delete' ? dialog.expense : null;
  const editing = dialog?.kind === 'form' ? dialog.expense : null;

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('expenses.title')}
        description={t('expenses.text')}
        actions={
          <span className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={() => setCategoriesOpen(true)}>
              {t('expenses.categories')}
            </Button>
            <Button
              disabled={!canWrite || categories.length === 0}
              onClick={() => open({ kind: 'form', expense: null })}
            >
              {t('expenses.new')}
            </Button>
          </span>
        }
      />
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}
      {me.impersonating ? (
        <Alert tone="info">{t('expenses.supportReadOnly')}</Alert>
      ) : !me.can_write ? (
        <Alert tone="warning">{t('expenses.readOnly')}</Alert>
      ) : null}
      {categories.length === 0 ? (
        <Alert tone="info">{t('expenses.noCategories')}</Alert>
      ) : null}
      {error ? <Alert tone="danger">{error}</Alert> : null}

      <div className="flex flex-wrap items-center gap-3">
        <Button
          variant="secondary"
          disabled={loading}
          onClick={() => go(addMonths(month, -1))}
        >
          {t('expenses.prev')}
        </Button>
        <h2 className="text-lg font-semibold capitalize text-ink">
          {monthTitle(month)}
        </h2>
        <Button
          variant="secondary"
          disabled={loading}
          onClick={() => go(addMonths(month, 1))}
        >
          {t('expenses.next')}
        </Button>
      </div>

      <p className="text-sm text-muted" aria-live="polite">
        {loading ? t('expenses.loading') : null}
      </p>

      {list ? (
        <div className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-md border border-control-border bg-surface p-4">
            <p className="text-sm text-muted">{t('expenses.total')}</p>
            <p className="text-2xl font-semibold text-ink">
              {formatBRL(list.total_cents)}
            </p>
          </div>
          {list.by_category.length > 0 ? (
            <div className="rounded-md border border-control-border bg-surface p-4">
              <p className="text-sm text-muted">{t('expenses.byCategory')}</p>
              <ul className="mt-1 space-y-1 text-sm text-ink">
                {list.by_category.map((group) => (
                  <li
                    key={group.category_id}
                    className="flex justify-between gap-3"
                  >
                    <span>{group.name}</span>
                    <span className="font-medium">
                      {formatBRL(group.total_cents)}
                    </span>
                  </li>
                ))}
              </ul>
            </div>
          ) : null}
        </div>
      ) : null}

      {list && list.data.length === 0 && !error && !loading ? (
        <EmptyState title={t('expenses.empty')} />
      ) : null}
      {list && list.data.length > 0 ? (
        <Table caption={t('expenses.caption')}>
          <thead>
            <Tr>
              <Th>{t('expenses.date')}</Th>
              <Th>{t('expenses.description')}</Th>
              <Th>{t('expenses.category')}</Th>
              <Th>{t('expenses.amount')}</Th>
              <Th>{t('expenses.actions')}</Th>
            </Tr>
          </thead>
          <tbody>
            {list.data.map((expense) => (
              <Tr key={expense.id}>
                <Td>{formatDay(expense.spent_on)}</Td>
                <Td>{expense.description}</Td>
                <Td>{expense.category.name}</Td>
                <Td>{formatBRL(expense.amount_cents)}</Td>
                <Td>
                  <span className="flex flex-wrap gap-2">
                    <Button
                      variant="secondary"
                      disabled={!canWrite}
                      onClick={() => open({ kind: 'form', expense })}
                      aria-label={`${t('expenses.edit')}: ${expense.description}`}
                    >
                      {t('expenses.edit')}
                    </Button>
                    <Button
                      variant="secondary"
                      disabled={!canWrite}
                      onClick={() => open({ kind: 'delete', expense })}
                      aria-label={`${t('expenses.delete')}: ${expense.description}`}
                    >
                      {t('expenses.delete')}
                    </Button>
                  </span>
                </Td>
              </Tr>
            ))}
          </tbody>
        </Table>
      ) : null}

      <Dialog
        open={dialog?.kind === 'form'}
        onClose={() => setDialog(null)}
        title={t(editing ? 'expenses.editTitle' : 'expenses.newTitle')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog(null)}>
              {t('expenses.cancel')}
            </Button>
            <Button loading={saving} onClick={() => void submit()}>
              {t('expenses.save')}
            </Button>
          </>
        }
      >
        <form className="space-y-4" onSubmit={(event) => void submit(event)}>
          {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
          <SelectField
            label={t('expenses.categoryField')}
            value={form.categoryId}
            error={fieldErrors.category_id}
            onChange={(event) =>
              setForm({ ...form, categoryId: event.target.value })
            }
          >
            {categories.map((category) => (
              <option key={category.id} value={category.id}>
                {category.name}
              </option>
            ))}
          </SelectField>
          <TextField
            label={t('expenses.descriptionField')}
            value={form.description}
            maxLength={255}
            error={fieldErrors.description}
            onChange={(event) =>
              setForm({ ...form, description: event.target.value })
            }
          />
          <TextField
            label={t('expenses.amountField')}
            inputMode="decimal"
            value={form.amount}
            error={fieldErrors.amount_cents}
            onChange={(event) =>
              setForm({ ...form, amount: event.target.value })
            }
          />
          <TextField
            label={t('expenses.dateField')}
            type="date"
            value={form.spentOn}
            error={fieldErrors.spent_on}
            onChange={(event) =>
              setForm({ ...form, spentOn: event.target.value })
            }
          />
        </form>
      </Dialog>

      <Dialog
        open={deleting !== null}
        onClose={() => setDialog(null)}
        title={t('expenses.deleteTitle')}
        description={
          deleting
            ? tf('expenses.deleteText', {
                description: deleting.description,
                amount: formatBRL(deleting.amount_cents),
              })
            : undefined
        }
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog(null)}>
              {t('expenses.cancel')}
            </Button>
            <Button loading={saving} onClick={() => void remove()}>
              {t('expenses.deleteConfirm')}
            </Button>
          </>
        }
      >
        {dialogError ? <Alert tone="danger">{dialogError}</Alert> : null}
      </Dialog>

      <ExpenseCategoriesDialog
        open={categoriesOpen}
        onClose={() => setCategoriesOpen(false)}
        categories={categories}
        canWrite={canWrite}
        onChange={(next, text) => {
          setCategories(next);
          setNotice({ tone: 'success', text });
          load(month);
        }}
      />
    </div>
  );
}
