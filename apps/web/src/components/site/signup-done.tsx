'use client';

import { t, tf } from '@/i18n';
import { formatDate } from '@/lib/format';
import { slugProblem } from '@/lib/slug';
import { tenantUrl } from '@/lib/tenant-url';
import { useLocation } from '@/lib/use-location';
import { Alert } from '@/components/ui/alert';
import { Card } from '@/components/ui/card';

/** Confirmação do cadastro: o endereço do painel (no mesmo domínio e porta desta página) e o fim do teste. */
export function SignupDone({
  slug,
  trialEndsAt,
}: {
  slug?: string;
  trialEndsAt?: string;
}) {
  const location = useLocation();
  const url =
    slug && location && slugProblem(slug) === null
      ? tenantUrl(slug, location, '/entrar')
      : null;

  const valid = slug && slugProblem(slug) === null;
  const ends =
    trialEndsAt && !Number.isNaN(Date.parse(trialEndsAt))
      ? formatDate(trialEndsAt)
      : null;

  if (!valid) {
    return <Alert tone="info">{t('signup.doneNoData')}</Alert>;
  }

  return (
    <Card className="mx-auto max-w-lg">
      <h1 className="text-2xl font-bold text-ink">{t('signup.doneTitle')}</h1>
      <p className="mt-2 text-ink">{t('signup.doneText')}</p>
      <p className="mt-4 text-sm text-muted">{t('signup.doneAddress')}</p>
      <p className="break-all font-semibold text-ink">
        {url ? url.replace(/\/entrar$/, '') : slug}
      </p>
      {ends ? (
        <p className="mt-2 text-sm text-ink">
          {tf('signup.doneTrial', { date: ends })}
        </p>
      ) : null}
      <p className="mt-1 text-sm text-muted">{t('signup.doneEmail')}</p>
      {url ? (
        <a
          href={url}
          className="mt-6 inline-flex min-h-11 items-center rounded-md bg-brand-600 px-6 text-sm font-semibold text-surface hover:bg-brand-700"
        >
          {t('signup.doneCta')}
        </a>
      ) : null}
    </Card>
  );
}
