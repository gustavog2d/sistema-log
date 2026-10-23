import { t } from '@/i18n';

/** Primeiro item da página para quem navega pelo teclado: leva direto ao conteúdo. */
export function SkipLink({ target = 'conteudo' }: { target?: string }) {
  return (
    <a
      href={`#${target}`}
      className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-50 focus:rounded-md focus:bg-surface focus:px-4 focus:py-2 focus:text-brand-700 focus:shadow-lg focus:outline-2 focus:outline-focus"
    >
      {t('common.skipToContent')}
    </a>
  );
}
