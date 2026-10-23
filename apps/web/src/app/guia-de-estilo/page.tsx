import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { contrastRatio, tokens, type ColorToken } from '@app/ui-tokens';
import { MarketingLayout } from '@/components/layout/marketing-layout';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader } from '@/components/ui/card';
import { CheckboxField, SelectField, TextField } from '@/components/ui/inputs';
import { Logo } from '@/components/ui/logo';
import { t } from '@/i18n';

export const metadata: Metadata = {
  title: t('styleGuide.title'),
  robots: { index: false },
};

const GROUPS: Array<[string, ColorToken[]]> = [
  [
    'brand',
    [
      'brand-50',
      'brand-100',
      'brand-200',
      'brand-300',
      'brand-400',
      'brand-500',
      'brand-600',
      'brand-700',
      'brand-800',
      'brand-900',
    ],
  ],
  ['accent', ['accent-400', 'accent-500', 'accent-600']],
  [
    'slate',
    [
      'slate-50',
      'slate-100',
      'slate-200',
      'slate-300',
      'slate-400',
      'slate-500',
      'slate-600',
      'slate-700',
      'slate-800',
      'slate-900',
    ],
  ],
  [
    'status',
    [
      'success-bg',
      'success-fg',
      'warning-bg',
      'warning-fg',
      'danger-bg',
      'danger-fg',
      'info-bg',
      'info-fg',
    ],
  ],
];

export default function StyleGuide() {
  // Só para desenvolvimento e homologação: em produção a página não existe.
  if (
    process.env.NODE_ENV === 'production' &&
    process.env.STYLE_GUIDE !== 'true'
  ) {
    notFound();
  }

  return (
    <MarketingLayout links={[]}>
      <div className="mx-auto max-w-6xl space-y-8 px-4 py-8">
        <PageHeader
          title={t('styleGuide.title')}
          description={t('styleGuide.description')}
        />
        <Card>
          <CardHeader
            title={t('styleGuide.logo')}
            description={t('styleGuide.logoNote')}
          />
          <div className="flex flex-wrap items-center gap-8">
            <Logo />
            <div className="rounded-md bg-slate-900 p-4">
              <Logo inverted />
            </div>
          </div>
        </Card>
        <Card>
          <CardHeader
            title={t('styleGuide.colors')}
            description={t('styleGuide.colorsNote')}
          />
          <div className="space-y-5">
            {GROUPS.map(([name, keys]) => (
              <div key={name}>
                <h3 className="mb-2 text-sm font-semibold capitalize text-ink">
                  {name}
                </h3>
                <ul className="flex flex-wrap gap-3">
                  {keys.map((key) => (
                    <li key={key} className="w-28 text-xs">
                      <div
                        className="h-12 rounded-md border border-line"
                        style={{ backgroundColor: tokens.color[key] }}
                      />
                      <p className="mt-1 font-medium text-ink">{key}</p>
                      <p className="text-muted">{tokens.color[key]}</p>
                      <p className="text-muted">
                        {contrastRatio(tokens.color[key], '#ffffff').toFixed(1)}
                        :1
                      </p>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </Card>
        <Card>
          <CardHeader title={t('styleGuide.components')} />
          <div className="space-y-6">
            <div className="flex flex-wrap gap-3">
              <Button>{t('styleGuide.primary')}</Button>
              <Button variant="accent">{t('styleGuide.accent')}</Button>
              <Button variant="secondary">{t('styleGuide.secondary')}</Button>
              <Button variant="ghost">{t('styleGuide.ghost')}</Button>
              <Button variant="danger">{t('styleGuide.danger')}</Button>
              <Button loading>{t('common.sending')}</Button>
              <Button disabled>{t('styleGuide.disabled')}</Button>
            </div>
            <div className="flex flex-wrap gap-2">
              <Badge tone="success">{t('styleGuide.success')}</Badge>
              <Badge tone="warning">{t('styleGuide.warning')}</Badge>
              <Badge tone="danger">{t('styleGuide.error')}</Badge>
              <Badge tone="info">{t('styleGuide.info')}</Badge>
              <Badge>{t('styleGuide.neutral')}</Badge>
            </div>
            <div className="grid gap-4 md:grid-cols-2">
              <TextField
                label={t('styleGuide.fieldName')}
                hint={t('styleGuide.fieldHint')}
              />
              <TextField
                label={t('styleGuide.fieldEmail')}
                error={t('styleGuide.fieldError')}
                defaultValue="abc"
              />
              <SelectField label={t('styleGuide.fieldSelect')}>
                <option>{t('styleGuide.optionA')}</option>
              </SelectField>
              <CheckboxField label={t('styleGuide.fieldCheck')} />
            </div>
            <Alert tone="info" title={t('styleGuide.info')}>
              {t('styleGuide.alertBody')}
            </Alert>
            <Alert tone="danger" title={t('styleGuide.error')}>
              {t('styleGuide.alertBody')}
            </Alert>
          </div>
        </Card>
      </div>
    </MarketingLayout>
  );
}
