import type { Metadata } from 'next';
import { TenantsView } from '@/components/admin/tenants-view';
import { t } from '@/i18n';
import type { Page as ListPage, TenantItem } from '@/lib/admin-types';
import { getPanelContext } from '@/server/request-context';
import { apiGet } from '@/server/session';

export const metadata: Metadata = { title: t('admin.tenants.title') };

export default async function Page() {
  const result = await apiGet<ListPage<TenantItem>>(
    'platform/tenants?limit=20',
    await getPanelContext(),
  );

  return <TenantsView initial={result.status === 200 ? result.data : null} />;
}
