import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { CouponsView } from '@/components/admin/coupons-view';
import { t } from '@/i18n';
import { canWrite, type Coupon } from '@/lib/admin-types';
import { getAdminSession } from '@/server/admin-session';
import { getPanelContext } from '@/server/request-context';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('admin.coupons.title') };

export default async function Page() {
  const session = await getAdminSession();

  if (session.kind !== 'ok') redirect('/admin/entrar');

  const result = await apiGet<{ data: Coupon[] }>(
    'platform/coupons',
    await getPanelContext(),
  );

  return (
    <CouponsView
      coupons={result.status === 200 && result.data ? result.data.data : null}
      canWrite={canWrite(session.me.role)}
    />
  );
}
