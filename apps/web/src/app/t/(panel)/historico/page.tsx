import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { AuditView } from '@/components/panel/audit-view';
import { NoPermission } from '@/components/panel/notices';
import { t } from '@/i18n';
import type { AuditPage } from '@/lib/audit';
import { canSee } from '@/lib/panel-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('audit.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');
  if (!canSee(session.me.user.role, 'audit')) return <NoPermission />;

  const result = await apiGet<AuditPage>(
    'audit-logs?limit=20',
    await getPanelContext(),
  );

  return <AuditView initial={result.status === 200 ? result.data : null} />;
}
