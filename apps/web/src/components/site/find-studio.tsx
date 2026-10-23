'use client';

import Link from 'next/link';
import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { slugProblem } from '@/lib/slug';
import { tenantUrl } from '@/lib/tenant-url';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { TextField } from '@/components/ui/inputs';

/** Leva a pessoa ao login do negócio dela: `{negócio}.{domínio}/entrar`. */
export function FindStudio({
  go = (url: string) => window.location.assign(url),
}: {
  go?: (url: string) => void;
}) {
  const [slug, setSlug] = useState('');
  const [error, setError] = useState<string | undefined>();

  function submit(event: FormEvent) {
    event.preventDefault();
    const clean = slug.trim().toLowerCase();

    if (slugProblem(clean) !== null) {
      setError(t('findStudio.invalid'));

      return;
    }

    setError(undefined);
    go(tenantUrl(clean, window.location, '/entrar'));
  }

  return (
    <Card className="mx-auto max-w-md">
      <h1 className="text-2xl font-bold text-ink">{t('findStudio.title')}</h1>
      <p className="mt-1 mb-4 text-muted">{t('findStudio.text')}</p>
      <form onSubmit={submit} noValidate className="flex flex-col gap-4">
        <TextField
          label={t('findStudio.label')}
          hint={t('findStudio.hint')}
          value={slug}
          onChange={(e) => setSlug(e.target.value)}
          error={error}
          autoComplete="off"
          autoCapitalize="none"
          spellCheck={false}
          required
        />
        <Button type="submit">{t('findStudio.submit')}</Button>
      </form>
      <p className="mt-6 text-sm text-muted">
        {t('findStudio.noAccount')}{' '}
        <Link
          href="/cadastro"
          className="font-semibold text-brand-700 underline"
        >
          {t('findStudio.createAccount')}
        </Link>
      </p>
    </Card>
  );
}
