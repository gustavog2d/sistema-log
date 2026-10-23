import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { LeadsView } from '@/components/admin/leads-view';
import { t } from '@/i18n';
import { canWrite, type Lead, type Page as ListPage } from '@/lib/admin-types';
import { getAdminSession } from '@/server/admin-session';
import { getPanelContext } from '@/server/request-context';
import { apiGet } from '@/server/session';

export const metadata: Metadata = { title: t('admin.leads.title') };

export default async function Page() {
  const session = await getAdminSession();

  if (session.kind !== 'ok') redirect('/admin/entrar');

  const result = await apiGet<ListPage<Lead>>(
    'platform/leads?limit=20',
    await getPanelContext(),
  );

  return (
    <LeadsView
      initial={result.status === 200 ? result.data : null}
      canWrite={canWrite(session.me.role)}
    />
  );
}
