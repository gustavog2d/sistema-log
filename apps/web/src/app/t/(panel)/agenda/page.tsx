import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { AgendaView } from '@/components/panel/agenda-view';
import { t } from '@/i18n';
import {
  mondayOf,
  todayInBusiness,
  weekRange,
  type AppointmentList,
} from '@/lib/appointment-types';
import type { ProfessionalPage } from '@/lib/professional-types';
import type { ServicePage } from '@/lib/service-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('agenda.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');

  const context = await getPanelContext();
  const monday = mondayOf(todayInBusiness());
  const range = weekRange(monday);
  const [appointments, professionals, services] = await Promise.all([
    apiGet<AppointmentList>(
      `appointments?from=${encodeURIComponent(range.from)}&to=${encodeURIComponent(range.to)}&limit=500`,
      context,
    ),
    apiGet<ProfessionalPage>('professionals?limit=50&status=active', context),
    apiGet<ServicePage>('services?limit=50&status=active', context),
  ]);

  return (
    <AgendaView
      me={session.me}
      initial={appointments.status === 200 ? appointments.data : null}
      weekStart={monday}
      professionals={professionals.data?.data ?? []}
      services={services.data?.data ?? []}
    />
  );
}
