import Link from 'next/link';
import type { ReactNode } from 'react';
import { t } from '@/i18n';
import { Logo } from '@/components/ui/logo';
import { SkipLink } from './skip-link';

export type NavLink = { href: string; label: string };

/** Layout do site público: cabeçalho com a marca e os links principais, conteúdo e rodapé. */
export function MarketingLayout({
  links,
  cta,
  footerText,
  children,
}: {
  links: NavLink[];
  cta?: NavLink;
  footerText?: string;
  children: ReactNode;
}) {
  return (
    <div className="flex min-h-dvh flex-col bg-canvas text-ink">
      <SkipLink />
      <header className="border-b border-line bg-surface">
        <div className="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-3">
          <Link href="/" aria-label={t('brand.logoLabel')}>
            <Logo />
          </Link>
          <nav aria-label={t('common.mainNavigation')}>
            <ul className="flex flex-wrap items-center gap-1">
              {links.map((link) => (
                <li key={link.href}>
                  <Link
                    href={link.href}
                    className="inline-flex min-h-11 items-center rounded-md px-3 text-sm font-medium text-brand-700 hover:bg-brand-50"
                  >
                    {link.label}
                  </Link>
                </li>
              ))}
              {cta ? (
                <li>
                  <Link
                    href={cta.href}
                    className="inline-flex min-h-11 items-center rounded-md bg-brand-600 px-4 text-sm font-semibold text-surface hover:bg-brand-700"
                  >
                    {cta.label}
                  </Link>
                </li>
              ) : null}
            </ul>
          </nav>
        </div>
      </header>
      <main id="conteudo" tabIndex={-1} className="flex-1 outline-none">
        {children}
      </main>
      <footer
        aria-label={t('common.footer')}
        className="border-t border-line bg-surface"
      >
        <div className="mx-auto max-w-6xl px-4 py-6 text-sm text-muted">
          {footerText ?? t('brand.tagline')}
        </div>
      </footer>
    </div>
  );
}
