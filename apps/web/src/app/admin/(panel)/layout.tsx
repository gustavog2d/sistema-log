import { headers } from 'next/headers';
import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { AdminFrame } from '@/components/admin/admin-frame';
import { Alert } from '@/components/ui/alert';
import { t } from '@/i18n';
import { safeAdminReturnPath } from '@/lib/safe-path';
import { getAdminSession } from '@/server/admin-session';

export const dynamic = 'force-dynamic';

/** Páginas da equipe: sem sessão vai ao login (guardando a página pedida); falha da API mostra aviso sem deslogar. */
export default async function AdminPanelLayout({
  children,
}: {
  children: ReactNode;
}) {
  const session = await getAdminSession();

  if (session.kind === 'unauthenticated') {
    const back = safeAdminReturnPath((await headers()).get('x-original-path'));

    redirect(
      back === '/admin'
        ? '/admin/entrar'
        : `/admin/entrar?voltar=${encodeURIComponent(back)}`,
    );
  }

  if (session.kind === 'error') {
    return (
      <div className="mx-auto max-w-xl p-6">
        <Alert tone="danger">{t('panel.sessionError')}</Alert>
      </div>
    );
  }

  return <AdminFrame me={session.me}>{children}</AdminFrame>;
}
