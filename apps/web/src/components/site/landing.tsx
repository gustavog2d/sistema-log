import Link from 'next/link';
import { t, tList, tf } from '@/i18n';
import type { PublicPlan } from '@/lib/plans';
import { Alert } from '@/components/ui/alert';
import { Card } from '@/components/ui/card';
import { ContactForm } from './contact-form';
import { PlanCards } from './plan-cards';

const NICHES = [
  'tatuagem',
  'manicure',
  'barbearia',
  'estetica_automotiva',
] as const;

/** Landing: promessa, nichos, benefícios, planos (da API), como funciona, contato e chamada para o cadastro. */
export function Landing({
  plans,
  highlight,
}: {
  plans: PublicPlan[] | null;
  highlight?: string;
}) {
  const benefits = tList<{ title: string; text: string }>('landing.benefits');
  const steps = tList<{ title: string; text: string }>('landing.steps');
  const trialDays = plans?.[0]?.trial_days ?? 7;

  return (
    <div>
      <section className="bg-brand-900 text-surface">
        <div className="mx-auto max-w-6xl px-4 py-16 md:py-24">
          <h1 className="max-w-3xl text-4xl font-bold tracking-tight md:text-5xl">
            {t('landing.heroTitle')}
          </h1>
          <p className="mt-4 max-w-2xl text-lg text-slate-200">
            {t('landing.heroText')}
          </p>
          <div className="mt-8 flex flex-wrap gap-3">
            <Link
              href="/cadastro"
              className="inline-flex min-h-11 items-center rounded-md bg-accent-500 px-6 text-base font-semibold text-ink hover:bg-accent-400"
            >
              {t('landing.heroCta')}
            </Link>
            <Link
              href="/#planos"
              className="inline-flex min-h-11 items-center rounded-md border border-slate-300 px-6 text-base font-semibold text-surface hover:bg-brand-800"
            >
              {t('landing.heroSecondary')}
            </Link>
          </div>
        </div>
      </section>

      <section
        aria-labelledby="nichos"
        className="mx-auto max-w-6xl px-4 py-12"
      >
        <h2 id="nichos" className="text-2xl font-bold text-ink">
          {t('landing.nichesTitle')}
        </h2>
        <ul className="mt-4 flex flex-wrap gap-2">
          {NICHES.map((niche) => (
            <li
              key={niche}
              className="rounded-full bg-brand-100 px-4 py-2 text-sm font-medium text-brand-900"
            >
              {t(`landing.niches.${niche}`)}
            </li>
          ))}
        </ul>
        <h2 className="mt-12 text-2xl font-bold text-ink">
          {t('landing.featuresTitle')}
        </h2>
        <ul className="mt-4 grid gap-4 md:grid-cols-3">
          {benefits.map((benefit) => (
            <li key={benefit.title}>
              <Card className="h-full">
                <h3 className="text-lg font-semibold text-ink">
                  {benefit.title}
                </h3>
                <p className="mt-2 text-sm text-muted">{benefit.text}</p>
              </Card>
            </li>
          ))}
        </ul>
      </section>

      <section
        id="planos"
        aria-labelledby="planos-titulo"
        className="bg-slate-100"
      >
        <div className="mx-auto max-w-6xl px-4 py-12">
          <h2 id="planos-titulo" className="text-2xl font-bold text-ink">
            {t('landing.plansTitle')}
          </h2>
          {plans && plans.length > 0 ? (
            <>
              <p className="mt-2 text-muted">
                {tf('landing.plansIntro', { days: trialDays })}
              </p>
              <div className="mt-6">
                <PlanCards plans={plans} highlight={highlight} />
              </div>
            </>
          ) : (
            <Alert tone="warning" className="mt-4">
              {t('landing.plansError')}
            </Alert>
          )}
        </div>
      </section>

      <section
        id="como-funciona"
        aria-labelledby="como-titulo"
        className="mx-auto max-w-6xl px-4 py-12"
      >
        <h2 id="como-titulo" className="text-2xl font-bold text-ink">
          {t('landing.howTitle')}
        </h2>
        <ol className="mt-4 grid gap-4 md:grid-cols-3">
          {steps.map((step, index) => (
            <li key={step.title} className="flex gap-3">
              <span
                aria-hidden="true"
                className="flex size-9 shrink-0 items-center justify-center rounded-full bg-brand-600 font-bold text-surface"
              >
                {index + 1}
              </span>
              <div>
                <h3 className="font-semibold text-ink">{step.title}</h3>
                <p className="mt-1 text-sm text-muted">{step.text}</p>
              </div>
            </li>
          ))}
        </ol>
      </section>

      <section
        id="contato"
        aria-labelledby="contato-titulo"
        className="bg-slate-100"
      >
        <div className="mx-auto max-w-3xl px-4 py-12">
          <h2 id="contato-titulo" className="text-2xl font-bold text-ink">
            {t('landing.contactTitle')}
          </h2>
          <p className="mt-2 mb-6 text-muted">{t('landing.contactIntro')}</p>
          <Card>
            <ContactForm />
          </Card>
        </div>
      </section>

      <section className="mx-auto max-w-6xl px-4 py-12 text-center">
        <h2 className="text-2xl font-bold text-ink">{t('landing.ctaTitle')}</h2>
        <p className="mt-2 text-muted">{t('landing.ctaText')}</p>
        <Link
          href="/cadastro"
          className="mt-6 inline-flex min-h-11 items-center rounded-md bg-brand-600 px-6 text-base font-semibold text-surface hover:bg-brand-700"
        >
          {t('landing.heroCta')}
        </Link>
      </section>
    </div>
  );
}
