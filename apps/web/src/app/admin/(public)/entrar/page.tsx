import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { LoginForm } from '@/components/panel/login-form';
import { t } from '@/i18n';
import { getAdminSession } from '@/server/admin-session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('admin.login.title') };

export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ voltar?: string }>;
}) {
  if ((await getAdminSession()).kind === 'ok') {
    redirect('/admin');
  }

  return <LoginForm variant="admin" returnTo={(await searchParams).voltar} />;
}
