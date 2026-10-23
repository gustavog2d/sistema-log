'use client';

import { usePathname, useRouter } from 'next/navigation';
import type { ReactNode } from 'react';
import { t } from '@/i18n';
import { call } from '@/lib/api';
import type { AdminMe } from '@/lib/admin-types';
import { AdminShell } from '@/components/layout/admin-shell';

const NAV = [
  { href: '/admin', label: 'admin.nav.home' },
  { href: '/admin/assinantes', label: 'admin.nav.tenants' },
  { href: '/admin/planos', label: 'admin.nav.plans' },
  { href: '/admin/cupons', label: 'admin.nav.coupons' },
  { href: '/admin/leads', label: 'admin.nav.leads' },
] as const;

/** Moldura das páginas da equipe da plataforma: menu, nome de quem entrou e saída (revoga o token). */
export function AdminFrame({
  me,
  children,
}: {
  me: AdminMe;
  children: ReactNode;
}) {
  const pathname = usePathname();
  const router = useRouter();

  async function logout() {
    try {
      await call('POST', 'platform/auth/logout');
    } catch {
      // Mesmo que a chamada falhe, a pessoa sai da tela: o cookie vence sozinho.
    } finally {
      router.replace('/admin/entrar');
      router.refresh();
    }
  }

  return (
    <AdminShell
      nav={NAV.map((item) => ({ href: item.href, label: t(item.label) }))}
      currentHref={pathname}
      user={{ name: me.name }}
      onLogout={() => void logout()}
      areaLabel={t(`admin.roles.${me.role}`)}
    >
      {children}
    </AdminShell>
  );
}
