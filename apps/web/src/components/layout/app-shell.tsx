'use client';

import Link from 'next/link';
import { useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import { t } from '@/i18n';
import { cn } from '@/lib/cn';
import { Button } from '@/components/ui/button';
import { Logo } from '@/components/ui/logo';
import { SkipLink } from './skip-link';

export type ShellNavItem = { href: string; label: string };

/**
 * Painel autenticado: menu lateral escuro (vira menu recolhível no celular), cabeçalho com a pessoa e a saída,
 * e a área principal. Serve ao painel do assinante e ao da equipe (muda o item de navegação e o rótulo).
 */
export function AppShell({
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
  areaLabel?: string;
  children: ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const toggle = useRef<HTMLButtonElement>(null);

  // Esc fecha o menu do celular e devolve o foco ao botão que o abre.
  function onKeyDown(event: KeyboardEvent<HTMLElement>) {
    if (event.key === 'Escape' && open) {
      setOpen(false);
      toggle.current?.focus();
    }
  }

  return (
    <div className="min-h-dvh bg-canvas text-ink md:grid md:grid-cols-[16rem_1fr]">
      <SkipLink />
      <aside className="bg-slate-900 text-slate-100" onKeyDown={onKeyDown}>
        <div className="flex items-center justify-between px-4 py-3 md:block md:py-5">
          <Logo inverted />
          <Button
            ref={toggle}
            variant="ghost"
            className="text-slate-100 hover:bg-slate-800 md:hidden"
            aria-expanded={open}
            aria-controls="menu-principal"
            onClick={() => setOpen((value) => !value)}
          >
            {open ? t('common.closeMenu') : t('common.openMenu')}
          </Button>
        </div>
        {areaLabel ? (
          <p className="hidden px-4 pb-2 text-xs uppercase tracking-wide text-slate-300 md:block">
            {areaLabel}
          </p>
        ) : null}
        <nav
          id="menu-principal"
          aria-label={t('common.mainNavigation')}
          className={cn('px-2 pb-4', open ? 'block' : 'hidden md:block')}
        >
          <ul className="flex flex-col gap-1">
            {nav.map((item) => {
              const current = item.href === currentHref;

              return (
                <li key={item.href}>
                  <Link
                    href={item.href}
                    aria-current={current ? 'page' : undefined}
                    className={cn(
                      'flex min-h-11 items-center rounded-md px-3 text-sm font-medium',
                      current
                        ? 'bg-slate-700 text-surface'
                        : 'text-slate-100 hover:bg-slate-800',
                    )}
                  >
                    {item.label}
                  </Link>
                </li>
              );
            })}
          </ul>
        </nav>
      </aside>
      <div className="flex min-w-0 flex-col">
        <header className="flex items-center justify-end gap-3 border-b border-line bg-surface px-4 py-2">
          {user ? (
            <span className="text-sm text-muted">{user.name}</span>
          ) : null}
          {onLogout ? (
            <Button variant="secondary" onClick={onLogout}>
              {t('common.logout')}
            </Button>
          ) : null}
        </header>
        <main
          id="conteudo"
          tabIndex={-1}
          className="mx-auto w-full max-w-5xl flex-1 p-4 outline-none md:p-8"
        >
          {children}
        </main>
      </div>
    </div>
  );
}
