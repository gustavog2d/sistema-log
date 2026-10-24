'use client';

import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { ExpenseCategory } from '@/lib/expense-types';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { TextField } from '@/components/ui/inputs';

function sorted(list: ExpenseCategory[]): ExpenseCategory[] {
  return [...list].sort((a, b) =>
    a.name.localeCompare(b.name, 'pt-BR', { sensitivity: 'base' }),
  );
}

/** Categorias de despesa: criar, renomear, excluir (só sem despesas) e criar as sugeridas. Dona e gerente. */
export function ExpenseCategoriesDialog({
  open,
  onClose,
  categories,
  onChange,
  canWrite,
}: {
  open: boolean;
  onClose: () => void;
  categories: ExpenseCategory[];
  /** Avisa a lista nova (ordem alfabética) e uma mensagem de sucesso. */
  onChange: (categories: ExpenseCategory[], notice: string) => void;
  canWrite: boolean;
}) {
  const [name, setName] = useState('');
  const [editing, setEditing] = useState<ExpenseCategory | null>(null);
  const [editName, setEditName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | null>(null);

  async function run(work: () => Promise<void>) {
    setBusy(true);
    setError(null);
    setFieldError(null);

    try {
      await work();
    } catch (failure) {
      if (failure instanceof ApiError && failure.fieldErrors.name) {
        setFieldError(failure.fieldErrors.name);
      } else if (
        failure instanceof ApiError &&
        failure.code === 'category_in_use'
      ) {
        setError(t('expenses.categoryInUse'));
      } else {
        setError(
          failure instanceof ApiError && failure.status === 403
            ? t('panel.noPermission')
            : failure instanceof ApiError
              ? failure.message
              : t('common.genericError'),
        );
      }
    } finally {
      setBusy(false);
    }
  }

  function add(event: FormEvent) {
    event.preventDefault();
    void run(async () => {
      const { data } = await call<ExpenseCategory>(
        'POST',
        'expense-categories',
        { body: { name: name.trim() } },
      );
      setName('');
      onChange(sorted([...categories, data]), t('expenses.categoryAdded'));
    });
  }

  function rename(event: FormEvent) {
    event.preventDefault();

    if (!editing) return;

    const target = editing;

    void run(async () => {
      const { data } = await call<ExpenseCategory>(
        'PATCH',
        `expense-categories/${target.id}`,
        { body: { name: editName.trim() } },
      );
      setEditing(null);
      onChange(
        sorted(categories.map((item) => (item.id === data.id ? data : item))),
        t('expenses.categoryRenamed'),
      );
    });
  }

  function remove(category: ExpenseCategory) {
    void run(async () => {
      await call('DELETE', `expense-categories/${category.id}`);
      onChange(
        categories.filter((item) => item.id !== category.id),
        t('expenses.categoryDeleted'),
      );
    });
  }

  function defaults() {
    void run(async () => {
      await call('POST', 'expense-categories/defaults');
      const { data } = await call<{ data: ExpenseCategory[] }>(
        'GET',
        'expense-categories',
      );
      onChange(sorted(data.data), t('expenses.categoryDefaultsDone'));
    });
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('expenses.categoriesTitle')}
      description={t('expenses.categoriesText')}
    >
      <div className="flex flex-col gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}

        {categories.length === 0 ? (
          <p className="text-muted">{t('expenses.categoryEmpty')}</p>
        ) : (
          <ul className="divide-y divide-control-border rounded-md border border-control-border">
            {categories.map((category) => (
              <li
                key={category.id}
                className="flex flex-wrap items-center justify-between gap-2 p-3"
              >
                {editing?.id === category.id ? (
                  <form
                    onSubmit={rename}
                    noValidate
                    className="flex w-full flex-wrap items-end gap-2"
                  >
                    <TextField
                      className="min-w-48 flex-1"
                      label={tf('expenses.categoryEditing', {
                        name: category.name,
                      })}
                      value={editName}
                      onChange={(event) => setEditName(event.target.value)}
                      error={fieldError ?? undefined}
                      maxLength={60}
                      autoComplete="off"
                    />
                    <Button type="submit" loading={busy}>
                      {t('expenses.categorySave')}
                    </Button>
                    <Button
                      variant="secondary"
                      onClick={() => {
                        setEditing(null);
                        setFieldError(null);
                      }}
                      disabled={busy}
                    >
                      {t('common.cancel')}
                    </Button>
                  </form>
                ) : (
                  <>
                    <span>
                      <span className="font-medium text-ink">
                        {category.name}
                      </span>{' '}
                      <span className="text-sm text-muted">
                        {tf('expenses.categoryCount', {
                          count: category.expenses_count,
                        })}
                      </span>
                    </span>
                    {canWrite ? (
                      <span className="flex gap-2">
                        <Button
                          variant="secondary"
                          disabled={busy}
                          onClick={() => {
                            setError(null);
                            setFieldError(null);
                            setEditName(category.name);
                            setEditing(category);
                          }}
                          aria-label={`${t('expenses.categoryRename')}: ${category.name}`}
                        >
                          {t('expenses.categoryRename')}
                        </Button>
                        <Button
                          variant="secondary"
                          disabled={busy}
                          onClick={() => remove(category)}
                          aria-label={`${t('expenses.categoryDelete')}: ${category.name}`}
                        >
                          {t('expenses.categoryDelete')}
                        </Button>
                      </span>
                    ) : null}
                  </>
                )}
              </li>
            ))}
          </ul>
        )}

        {canWrite ? (
          <>
            <form
              onSubmit={add}
              noValidate
              className="flex flex-wrap items-end gap-2"
            >
              <TextField
                className="min-w-48 flex-1"
                label={t('expenses.categoryName')}
                value={name}
                onChange={(event) => setName(event.target.value)}
                error={editing ? undefined : (fieldError ?? undefined)}
                maxLength={60}
                autoComplete="off"
              />
              <Button type="submit" loading={busy && !editing}>
                {t('expenses.categoryAdd')}
              </Button>
            </form>
            <Button variant="secondary" disabled={busy} onClick={defaults}>
              {t('expenses.categoryDefaults')}
            </Button>
          </>
        ) : null}

        <div className="flex justify-end">
          <Button variant="secondary" onClick={onClose}>
            {t('expenses.close')}
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
