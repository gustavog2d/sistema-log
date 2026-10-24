'use client';

import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { ServiceCategory } from '@/lib/service-types';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { TextField } from '@/components/ui/inputs';

/** Gestão das categorias de serviços: criar, renomear e excluir (só sem serviços). Dona e gerente. */
export function ServiceCategoriesDialog({
  open,
  onClose,
  categories,
  onChange,
  canWrite,
}: {
  open: boolean;
  onClose: () => void;
  categories: ServiceCategory[];
  /** Avisa a lista nova (ordem alfabética) e uma mensagem de sucesso. */
  onChange: (categories: ServiceCategory[], notice: string) => void;
  canWrite: boolean;
}) {
  const [name, setName] = useState('');
  const [editing, setEditing] = useState<ServiceCategory | null>(null);
  const [editName, setEditName] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldError, setFieldError] = useState<string | null>(null);

  function sorted(list: ServiceCategory[]): ServiceCategory[] {
    return [...list].sort((a, b) =>
      a.name.localeCompare(b.name, 'pt-BR', { sensitivity: 'base' }),
    );
  }

  async function run(work: () => Promise<void>) {
    setBusy(true);
    setError(null);
    setFieldError(null);

    try {
      await work();
    } catch (failure) {
      if (failure instanceof ApiError && failure.fieldErrors.name) {
        setFieldError(failure.fieldErrors.name);
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
      const { data } = await call<ServiceCategory>(
        'POST',
        'service-categories',
        {
          body: { name: name.trim() },
        },
      );
      setName('');
      onChange(sorted([...categories, data]), t('services.categoryAdded'));
    });
  }

  function rename(event: FormEvent) {
    event.preventDefault();

    if (!editing) return;

    const target = editing;

    void run(async () => {
      const { data } = await call<ServiceCategory>(
        'PATCH',
        `service-categories/${target.id}`,
        { body: { name: editName.trim() } },
      );
      setEditing(null);
      onChange(
        sorted(categories.map((item) => (item.id === data.id ? data : item))),
        t('services.categoryRenamed'),
      );
    });
  }

  function remove(category: ServiceCategory) {
    void run(async () => {
      await call('DELETE', `service-categories/${category.id}`);
      onChange(
        categories.filter((item) => item.id !== category.id),
        t('services.categoryDeleted'),
      );
    });
  }

  return (
    <Dialog
      open={open}
      onClose={onClose}
      title={t('services.categoriesTitle')}
      description={t('services.categoriesText')}
    >
      <div className="flex flex-col gap-4">
        {error ? <Alert tone="danger">{error}</Alert> : null}

        {categories.length === 0 ? (
          <p className="text-muted">{t('services.categoryEmpty')}</p>
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
                      label={tf('services.categoryEditing', {
                        name: category.name,
                      })}
                      value={editName}
                      onChange={(event) => setEditName(event.target.value)}
                      error={fieldError ?? undefined}
                      maxLength={60}
                      autoComplete="off"
                    />
                    <Button type="submit" loading={busy}>
                      {t('services.categorySave')}
                    </Button>
                    <Button
                      variant="secondary"
                      onClick={() => setEditing(null)}
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
                        {tf('services.categoryCount', {
                          count: category.services_count,
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
                          aria-label={`${t('services.categoryRename')}: ${category.name}`}
                        >
                          {t('services.categoryRename')}
                        </Button>
                        <Button
                          variant="secondary"
                          disabled={busy}
                          onClick={() => remove(category)}
                          aria-label={`${t('services.categoryDelete')}: ${category.name}`}
                        >
                          {t('services.categoryDelete')}
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
          <form
            onSubmit={add}
            noValidate
            className="flex flex-wrap items-end gap-2"
          >
            <TextField
              className="min-w-48 flex-1"
              label={t('services.categoryName')}
              value={name}
              onChange={(event) => setName(event.target.value)}
              error={editing ? undefined : (fieldError ?? undefined)}
              maxLength={60}
              autoComplete="off"
            />
            <Button type="submit" loading={busy && !editing}>
              {t('services.categoryAdd')}
            </Button>
          </form>
        ) : null}

        <div className="flex justify-end">
          <Button variant="secondary" onClick={onClose}>
            {t('services.close')}
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
