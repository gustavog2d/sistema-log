import { t, tf } from '@/i18n';
import { formatDate } from '@/lib/format';
import {
  canSee,
  type Area,
  type Me,
  type Subscription,
} from '@/lib/panel-types';

export type NavItem = { href: string; label: string; area: Area };

const ALL_NAV: NavItem[] = [
  { href: '/', label: 'panel.nav.home', area: 'home' },
  {
    href: '/assinatura',
    label: 'panel.nav.subscription',
    area: 'subscription',
  },
  { href: '/agenda', label: 'panel.nav.agenda', area: 'agenda' },
  { href: '/clientes', label: 'panel.nav.clients', area: 'clients' },
  { href: '/servicos', label: 'panel.nav.services', area: 'services' },
  {
    href: '/profissionais',
    label: 'panel.nav.professionals',
    area: 'professionals',
  },
  { href: '/equipe', label: 'panel.nav.team', area: 'team' },
  { href: '/historico', label: 'panel.nav.audit', area: 'audit' },
  { href: '/conta', label: 'panel.nav.account', area: 'account' },
];

/** Itens do menu que o papel pode usar (a API também decide; o menu só reflete). */
export function navFor(me: Me): Array<{ href: string; label: string }> {
  return ALL_NAV.filter((item) => canSee(me.user.role, item.area)).map(
    (item) => ({
      href: item.href,
      label: t(item.label as 'panel.nav.home'),
    }),
  );
}

export type Banner = {
  key: string;
  tone: 'warning' | 'info' | 'danger';
  text: string;
  action?: { href: string; label: string };
};

const DAY = 86_400_000;

/** Dias inteiros que faltam até a data (arredondado para cima; nunca negativo). */
export function daysLeft(iso: string, now: number): number {
  return Math.max(0, Math.ceil((Date.parse(iso) - now) / DAY));
}

/** Faixas do topo do painel: acesso de suporte, assinatura suspensa/encerrada, cancelamento pendente e teste em andamento. */
export function bannersFor(
  me: Me,
  subscription: Subscription | null,
  now: number = Date.now(),
): Banner[] {
  const banners: Banner[] = [];

  if (me.impersonating) {
    banners.push({
      key: 'support',
      tone: 'warning',
      text: t('panel.banner.support'),
    });
  }

  if (!subscription) {
    return banners;
  }

  if (subscription.status === 'suspended') {
    banners.push({
      key: 'suspended',
      tone: 'danger',
      text: t('panel.banner.suspended'),
      ...(me.user.role === 'owner' && !me.impersonating
        ? {
            action: {
              href: '/assinatura',
              label: t('panel.banner.suspendedAction'),
            },
          }
        : {}),
    });

    if (me.user.role !== 'owner') {
      banners.push({
        key: 'suspended-other',
        tone: 'info',
        text: t('panel.banner.suspendedOther'),
      });
    }
  } else if (subscription.status === 'canceled') {
    banners.push({
      key: 'canceled',
      tone: 'danger',
      text: t('panel.banner.canceled'),
    });
  } else if (subscription.cancel_at_period_end) {
    const until =
      subscription.status === 'trialing'
        ? subscription.trial_ends_at
        : subscription.current_period_end;

    if (until) {
      banners.push({
        key: 'cancel-pending',
        tone: 'warning',
        text: tf('panel.banner.cancelPending', { date: formatDate(until) }),
      });
    }
  } else if (subscription.status === 'trialing' && subscription.trial_ends_at) {
    const days = daysLeft(subscription.trial_ends_at, now);
    const date = formatDate(subscription.trial_ends_at);
    // Menos de um dia inteiro: último dia (o arredondamento para cima dá 1).
    banners.push({
      key: 'trial',
      tone: 'info',
      text:
        days <= 1
          ? tf('panel.banner.trialLast', { date })
          : tf('panel.banner.trial', { days, date }),
    });
  }

  return banners;
}
