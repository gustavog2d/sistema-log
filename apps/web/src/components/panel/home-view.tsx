import Link from 'next/link';
import { t, tf } from '@/i18n';
import { formatBRL, formatDate } from '@/lib/format';
import { canSee, type Me, type Subscription } from '@/lib/panel-types';
import { PageHeader } from '@/components/layout/page-header';
import { Badge } from '@/components/ui/badge';
import { Card, CardHeader } from '@/components/ui/card';
import { StatusBadge } from './status-badge';

/** Início do painel: boas-vindas, situação da assinatura (para quem a vê) e atalhos. */
export function HomeView({
  me,
  subscription,
}: {
  me: Me;
  subscription: Subscription | null;
}) {
  const shortcuts = [
    {
      href: '/assinatura',
      label: t('panel.nav.subscription'),
      area: 'subscription' as const,
    },
    { href: '/equipe', label: t('panel.nav.team'), area: 'team' as const },
    { href: '/conta', label: t('panel.nav.account'), area: 'account' as const },
  ].filter((item) => canSee(me.user.role, item.area));

  return (
    <div className="space-y-6">
      <PageHeader
        title={tf('panel.homeTitle', {
          name: me.user.name.split(' ')[0] ?? me.user.name,
        })}
        description={tf('panel.homeText', { business: me.tenant.name })}
      />
      {subscription && canSee(me.user.role, 'subscription') ? (
        <Card>
          <CardHeader title={t('panel.homeStatus')} />
          <dl className="grid gap-3 text-sm sm:grid-cols-3">
            <div>
              <dt className="text-muted">{t('subscription.plan')}</dt>
              <dd className="font-semibold text-ink">
                {subscription.plan.name}
              </dd>
            </div>
            <div>
              <dt className="text-muted">{t('subscription.price')}</dt>
              <dd className="font-semibold text-ink">
                {formatBRL(subscription.price_cents)} /{' '}
                {t(
                  subscription.cycle === 'monthly'
                    ? 'signup.periodMonth'
                    : 'signup.periodYear',
                )}
              </dd>
            </div>
            <div>
              <dt className="text-muted">{t('subscription.nextCharge')}</dt>
              <dd className="font-semibold text-ink">
                {subscription.next_charge_at
                  ? formatDate(subscription.next_charge_at)
                  : t('subscription.none')}
              </dd>
            </div>
          </dl>
          <p className="mt-4">
            <StatusBadge subscription={subscription} />
          </p>
        </Card>
      ) : null}
      <Card>
        <CardHeader title={t('panel.homeShortcuts')} />
        <ul className="flex flex-wrap gap-2">
          {shortcuts.map((item) => (
            <li key={item.href}>
              <Link
                href={item.href}
                className="inline-flex min-h-11 items-center rounded-md border border-control-border px-4 text-sm font-medium text-brand-700 hover:bg-brand-50"
              >
                {item.label}
              </Link>
            </li>
          ))}
        </ul>
        <p className="mt-4 text-sm text-muted">
          <Badge tone="info">{t('panel.soon')}</Badge>
        </p>
      </Card>
    </div>
  );
}
