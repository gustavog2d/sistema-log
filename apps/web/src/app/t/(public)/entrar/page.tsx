import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { LoginForm } from '@/components/panel/login-form';
import { t } from '@/i18n';
import { getPanelSession } from '@/server/panel-session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('login.title') };

export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ voltar?: string; etapa?: string }>;
}) {
  // Quem já está logada não precisa do login.
  if ((await getPanelSession()).kind === 'ok') {
    redirect('/');
  }

  const { voltar, etapa } = await searchParams;

  return (
    <LoginForm
      returnTo={voltar}
      startAt={
        etapa === 'codigo'
          ? 'code'
          : etapa === 'configurar'
            ? 'setup'
            : 'credentials'
      }
    />
  );
}
