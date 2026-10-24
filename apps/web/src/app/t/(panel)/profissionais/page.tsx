import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { ProfessionalsView } from '@/components/panel/professionals-view';
import { t } from '@/i18n';
import type { ProfessionalPage, TimeOffPage } from '@/lib/professional-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('professionals.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');

  const context = await getPanelContext();
  const [professionals, timeOffs] = await Promise.all([
    apiGet<ProfessionalPage>('professionals?limit=50&status=all', context),
    apiGet<TimeOffPage>(
      `time-offs?limit=20&from=${encodeURIComponent(new Date().toISOString())}`,
      context,
    ),
  ]);

  return (
    <ProfessionalsView
      me={session.me}
      initial={professionals.status === 200 ? professionals.data : null}
      timeOffs={timeOffs.status === 200 ? timeOffs.data : null}
    />
  );
}
