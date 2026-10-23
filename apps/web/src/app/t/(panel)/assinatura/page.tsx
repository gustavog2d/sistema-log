import { redirect } from 'next/navigation';
import type { Metadata } from 'next';
import { LoadError, NoPermission } from '@/components/panel/notices';
import {
  SubscriptionView,
  type InvoicePage,
} from '@/components/panel/subscription-view';
import { t } from '@/i18n';
import { canSee } from '@/lib/panel-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const metadata: Metadata = { title: t('subscription.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');
  if (!canSee(session.me.user.role, 'subscription')) return <NoPermission />;
  if (!session.subscription) return <LoadError />;

  const invoices = await apiGet<InvoicePage>(
    'subscription/invoices?limit=20',
    await getPanelContext(),
  );

  return (
    <SubscriptionView
      me={session.me}
      subscription={session.subscription}
      invoices={invoices.data ?? { data: [], next_cursor: null }}
    />
  );
}
