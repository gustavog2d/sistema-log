import type { ReactNode } from 'react';
import { AppShell, type ShellNavItem } from './app-shell';

/** Painel da equipe da plataforma: o mesmo esqueleto do painel do assinante, com o rótulo da área da equipe. */
export function AdminShell({
  nav,
  currentHref,
  user,
  onLogout,
  areaLabel,
  children,
}: {
  nav: ShellNavItem[];
  currentHref: string;
  user?: { name: string };
  onLogout?: () => void;
  areaLabel: string;
  children: ReactNode;
}) {
  return (
    <AppShell
      nav={nav}
      currentHref={currentHref}
      user={user}
      onLogout={onLogout}
      areaLabel={areaLabel}
    >
      {children}
    </AppShell>
  );
}
