import { headers } from 'next/headers';
import { redirect } from 'next/navigation';
import type { ReactNode } from 'react';
import { PanelFrame } from '@/components/panel/panel-frame';
import { Alert } from '@/components/ui/alert';
import { t } from '@/i18n';
import { safeReturnPath } from '@/lib/safe-path';
import { getPanelSession } from '@/server/panel-session';

export const dynamic = 'force-dynamic';

/** Páginas logadas: sem sessão vai ao login (guardando a página pedida); falha da API mostra aviso sem deslogar. */
export default async function PanelLayout({
  children,
}: {
  children: ReactNode;
}) {
  const session = await getPanelSession();

  if (session.kind === 'unauthenticated') {
    const back = safeReturnPath((await headers()).get('x-original-path'));

    redirect(
      back === '/' ? '/entrar' : `/entrar?voltar=${encodeURIComponent(back)}`,
    );
  }

  if (session.kind === 'error') {
    return (
      <div className="mx-auto max-w-xl p-6">
        <Alert tone="danger">{t('panel.sessionError')}</Alert>
      </div>
    );
  }

  return (
    <PanelFrame me={session.me} subscription={session.subscription}>
      {children}
    </PanelFrame>
  );
}
