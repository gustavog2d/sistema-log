import { t } from '@/i18n';
import { Alert } from '@/components/ui/alert';

/** Termos e privacidade: textos provisórios, em tradução, para o jurídico substituir. */
export function Legal({ kind }: { kind: 'terms' | 'privacy' }) {
  return (
    <article className="mx-auto max-w-3xl space-y-4 px-4 py-10">
      <h1 className="text-3xl font-bold text-ink">
        {t(kind === 'terms' ? 'legal.termsTitle' : 'legal.privacyTitle')}
      </h1>
      <Alert tone="warning">{t('legal.provisional')}</Alert>
      <p className="text-ink">
        {t(kind === 'terms' ? 'legal.termsBody' : 'legal.privacyBody')}
      </p>
    </article>
  );
}
