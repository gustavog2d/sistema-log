import type { Metadata } from 'next';
import { AccountView } from '@/components/panel/account-view';
import { t } from '@/i18n';
import { getPanelSession } from '@/server/panel-session';

export const metadata: Metadata = { title: t('account.title') };

export default async function Page() {
  const session = await getPanelSession();

  return session.kind === 'ok' ? <AccountView me={session.me} /> : null;
}
