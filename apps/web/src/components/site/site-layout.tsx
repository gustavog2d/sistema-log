import type { ReactNode } from 'react';
import { MarketingLayout } from '@/components/layout/marketing-layout';
import { t } from '@/i18n';

/** Moldura das páginas públicas: cabeçalho com os links do site, chamada para o cadastro e rodapé. */
export function SiteLayout({ children }: { children: ReactNode }) {
  return (
    <MarketingLayout
      links={[
        { href: '/#planos', label: t('site.nav.plans') },
        { href: '/#como-funciona', label: t('site.nav.how') },
        { href: '/#contato', label: t('site.nav.contact') },
        { href: '/entrar', label: t('site.nav.signIn') },
      ]}
      cta={{ href: '/cadastro', label: t('site.nav.start') }}
      footerText={t('site.footer')}
    >
      {children}
    </MarketingLayout>
  );
}
