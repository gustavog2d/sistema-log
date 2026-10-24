import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { ServicesView } from '@/components/panel/services-view';
import { t } from '@/i18n';
import type { ServiceCategory, ServicePage } from '@/lib/service-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('services.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');

  const context = await getPanelContext();
  const [services, categories] = await Promise.all([
    apiGet<ServicePage>('services?limit=20&status=active', context),
    apiGet<{ data: ServiceCategory[] }>('service-categories', context),
  ]);

  return (
    <ServicesView
      me={session.me}
      initial={services.status === 200 ? services.data : null}
      categories={
        categories.status === 200 && categories.data ? categories.data.data : []
      }
    />
  );
}
