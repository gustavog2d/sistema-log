'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import type { ReactNode } from 'react';
import { call } from '@/lib/api';
import type { Me, Subscription } from '@/lib/panel-types';
import { AppShell } from '@/components/layout/app-shell';
import { Alert } from '@/components/ui/alert';
import { bannersFor, navFor } from './panel-model';

/** Moldura das páginas logadas: menu pelo papel, faixas de situação (assinatura e suporte) e saída. */
export function PanelFrame({
  me,
  subscription,
  now,
  children,
}: {
  me: Me;
  subscription: Subscription | null;
  now?: number;
  children: ReactNode;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const banners = bannersFor(me, subscription, now);

  async function logout() {
    try {
      await call('POST', 'auth/logout');
    } catch {
      // Mesmo que a chamada falhe, a pessoa sai da tela: o cookie vence sozinho.
    } finally {
      router.replace('/entrar');
      router.refresh();
    }
  }

  return (
    <AppShell
      nav={navFor(me)}
      currentHref={pathname}
      user={{ name: me.user.name }}
      onLogout={() => void logout()}
      areaLabel={me.tenant.name}
    >
      <div className="space-y-3 pb-4">
        {banners.map((banner) => (
          <Alert key={banner.key} tone={banner.tone}>
            <span className="flex flex-wrap items-center justify-between gap-3">
              <span>{banner.text}</span>
              {banner.action ? (
                <Link
                  href={banner.action.href}
                  className="font-semibold underline"
                >
                  {banner.action.label}
                </Link>
              ) : null}
            </span>
          </Alert>
        ))}
      </div>
      {children}
    </AppShell>
  );
}
