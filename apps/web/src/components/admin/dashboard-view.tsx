import Link from 'next/link';
import { t, tf } from '@/i18n';
import type { AdminMe } from '@/lib/admin-types';
import { PageHeader } from '@/components/layout/page-header';
import { Card } from '@/components/ui/card';

export type Count = { value: number; more: boolean } | null;

export type Summary = {
  trialing: Count;
  active: Count;
  suspended: Count;
  canceled: Count;
  newLeads: Count;
  activeCoupons: Count;
};

function show(count: Count): string {
  return count === null ? '—' : `${count.value}${count.more ? '+' : ''}`;
}

/** Resumo da equipe: quantos negócios por situação, leads novos e cupons ativos (limitado à primeira página da API). */
export function DashboardView({
  me,
  summary,
}: {
  me: AdminMe;
  summary: Summary;
}) {
  const cards: { key: string; label: string; count: Count; href: string }[] = [
    {
      key: 'trialing',
      label: t('status.trialing'),
      count: summary.trialing,
      href: '/admin/assinantes',
    },
    {
      key: 'active',
      label: t('status.active'),
      count: summary.active,
      href: '/admin/assinantes',
    },
    {
      key: 'suspended',
      label: t('status.suspended'),
      count: summary.suspended,
      href: '/admin/assinantes',
    },
    {
      key: 'canceled',
      label: t('status.canceled'),
      count: summary.canceled,
      href: '/admin/assinantes',
    },
    {
      key: 'leads',
      label: t('admin.home.newLeads'),
      count: summary.newLeads,
      href: '/admin/leads',
    },
    {
      key: 'coupons',
      label: t('admin.home.activeCoupons'),
      count: summary.activeCoupons,
      href: '/admin/cupons',
    },
  ];

  return (
    <div className="space-y-6">
      <PageHeader
        title={tf('admin.home.title', { name: me.name })}
        description={t('admin.home.text')}
      />
      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {cards.map((card) => (
          <li key={card.key}>
            <Card>
              <p className="text-sm text-muted">{card.label}</p>
              <p className="mt-1 text-3xl font-bold text-ink">
                {show(card.count)}
              </p>
              <Link
                href={card.href}
                className="mt-2 inline-block text-brand-700 underline"
              >
                {t('admin.home.see')}
                <span className="sr-only"> {card.label}</span>
              </Link>
            </Card>
          </li>
        ))}
      </ul>
    </div>
  );
}
