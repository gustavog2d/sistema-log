import Link from 'next/link';
import ptBR from '@/i18n/pt-BR.json';
import { t, tf } from '@/i18n';
import { cn } from '@/lib/cn';
import { formatBRL, yearlySavings } from '@/lib/format';
import type { PublicPlan } from '@/lib/plans';
import { Badge } from '@/components/ui/badge';

function featureLabel(key: string): string {
  return (ptBR.features as Record<string, string>)[key] ?? key;
}

/** Planos da API com preço mensal e anual (o desconto e a economia saem dos preços, nunca de regra fixa). */
export function PlanCards({
  plans,
  highlight,
}: {
  plans: PublicPlan[];
  highlight?: string;
}) {
  return (
    <ul className="grid gap-4 md:grid-cols-3">
      {plans.map((plan) => {
        const savings = yearlySavings(
          plan.monthly_price_cents,
          plan.yearly_price_cents,
        );
        const featured = plan.key === highlight;

        return (
          <li
            key={plan.key}
            className={cn(
              'flex flex-col rounded-lg border bg-surface p-6 shadow-sm',
              featured
                ? 'border-brand-600 ring-2 ring-brand-600'
                : 'border-line',
            )}
          >
            <h3 className="text-xl font-bold text-ink">{plan.name}</h3>
            <p className="mt-3 text-3xl font-bold text-ink">
              {formatBRL(plan.monthly_price_cents)}
              <span className="ml-1 text-sm font-normal text-muted">
                {t('landing.perMonth')}
              </span>
            </p>
            <p className="mt-1 text-sm text-muted">
              {formatBRL(plan.yearly_price_cents)} {t('landing.perYear')}
              {savings > 0 ? (
                <>
                  {' · '}
                  <Badge tone="success">
                    {tf('landing.yearlyNote', { amount: formatBRL(savings) })}
                  </Badge>
                </>
              ) : null}
            </p>
            <p className="mt-3 text-sm font-medium text-brand-700">
              {tf('signup.subtitle', { days: plan.trial_days })}
            </p>
            <ul className="mt-4 flex-1 space-y-1.5 text-sm text-ink">
              {plan.features
                .filter((feature) => feature.enabled)
                .map((feature) => (
                  <li key={feature.feature} className="flex gap-2">
                    <span aria-hidden="true" className="text-success-fg">
                      ✓
                    </span>
                    <span>
                      {featureLabel(feature.feature)}
                      {feature.limit !== null
                        ? ` (${tf('landing.upTo', { limit: feature.limit })})`
                        : ''}
                    </span>
                  </li>
                ))}
            </ul>
            <Link
              href={`/cadastro?plano=${encodeURIComponent(plan.key)}`}
              className={cn(
                'mt-6 inline-flex min-h-11 items-center justify-center rounded-md px-4 text-sm font-semibold',
                featured
                  ? 'bg-brand-600 text-surface hover:bg-brand-700'
                  : 'border border-control-border text-ink hover:bg-slate-100',
              )}
            >
              {t('landing.choosePlan')}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}
