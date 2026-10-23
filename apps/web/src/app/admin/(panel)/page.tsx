import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { DashboardView, type Count } from '@/components/admin/dashboard-view';
import { t } from '@/i18n';
import type { Coupon, Lead, Page, TenantItem } from '@/lib/admin-types';
import { getAdminSession } from '@/server/admin-session';
import { getPanelContext } from '@/server/request-context';
import { apiGet } from '@/server/session';

export const metadata: Metadata = { title: t('admin.home.pageTitle') };

function count<T>(result: { status: number; data: Page<T> | null }): Count {
  return result.status === 200 && result.data
    ? { value: result.data.data.length, more: result.data.next_cursor !== null }
    : null;
}

export default async function Page() {
  const session = await getAdminSession();

  if (session.kind !== 'ok') redirect('/admin/entrar');

  const context = await getPanelContext();
  const [trialing, active, suspended, canceled, leads, coupons] =
    await Promise.all([
      apiGet<Page<TenantItem>>(
        'platform/tenants?status=trialing&limit=50',
        context,
      ),
      apiGet<Page<TenantItem>>(
        'platform/tenants?status=active&limit=50',
        context,
      ),
      apiGet<Page<TenantItem>>(
        'platform/tenants?status=suspended&limit=50',
        context,
      ),
      apiGet<Page<TenantItem>>(
        'platform/tenants?status=canceled&limit=50',
        context,
      ),
      apiGet<Page<Lead>>('platform/leads?status=new&limit=50', context),
      apiGet<{ data: Coupon[] }>('platform/coupons', context),
    ]);

  return (
    <DashboardView
      me={session.me}
      summary={{
        trialing: count(trialing),
        active: count(active),
        suspended: count(suspended),
        canceled: count(canceled),
        newLeads: count(leads),
        activeCoupons:
          coupons.status === 200 && coupons.data
            ? {
                value: coupons.data.data.filter((c) => c.status === 'active')
                  .length,
                more: false,
              }
            : null,
      }}
    />
  );
}
