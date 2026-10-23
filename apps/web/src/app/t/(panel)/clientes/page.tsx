import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { ClientsView } from '@/components/panel/clients-view';
import { t } from '@/i18n';
import type { ClientPage } from '@/lib/client-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('clients.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');

  const result = await apiGet<ClientPage>(
    'clients?limit=20',
    await getPanelContext(),
  );

  return (
    <ClientsView
      me={session.me}
      initial={result.status === 200 ? result.data : null}
    />
  );
}
