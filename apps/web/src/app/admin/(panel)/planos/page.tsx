import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { PlansView } from '@/components/admin/plans-view';
import { t } from '@/i18n';
import { canWrite, type Plan } from '@/lib/admin-types';
import { getAdminSession } from '@/server/admin-session';
import { getPanelContext } from '@/server/request-context';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('admin.plans.title') };

export default async function Page() {
  const session = await getAdminSession();

  if (session.kind !== 'ok') redirect('/admin/entrar');

  const result = await apiGet<{ data: Plan[] }>(
    'platform/plans',
    await getPanelContext(),
  );

  return (
    <PlansView
      plans={result.status === 200 && result.data ? result.data.data : null}
      canWrite={canWrite(session.me.role)}
    />
  );
}
