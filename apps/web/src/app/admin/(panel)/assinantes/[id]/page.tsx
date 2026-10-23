import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { TenantDetailView } from '@/components/admin/tenant-detail';
import { Alert } from '@/components/ui/alert';
import { t } from '@/i18n';
import { canWrite, type TenantDetail } from '@/lib/admin-types';
import { getAdminSession } from '@/server/admin-session';
import { getPanelContext } from '@/server/request-context';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('admin.detail.pageTitle') };

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

export default async function Page({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const session = await getAdminSession();

  if (!UUID.test(id) || session.kind !== 'ok') notFound();

  const result = await apiGet<TenantDetail>(
    `platform/tenants/${id}`,
    await getPanelContext(),
  );

  if (result.status === 404) notFound();
  if (result.status !== 200 || !result.data) {
    return <Alert tone="danger">{t('admin.loadError')}</Alert>;
  }

  return (
    <TenantDetailView
      tenant={result.data}
      canWrite={canWrite(session.me.role)}
    />
  );
}
