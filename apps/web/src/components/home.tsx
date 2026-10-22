import { t } from '@/i18n';

export type ApiStatus = 'ok' | 'down';

export function Home({ apiStatus }: { apiStatus: ApiStatus }) {
  return (
    <main className="mx-auto max-w-xl p-8">
      <h1 data-testid="home-title" className="text-2xl font-semibold">
        {t('home.title')}
      </h1>
      <p data-testid="home-api-status" className="mt-4">
        {apiStatus === 'ok' ? t('home.apiOk') : t('home.apiDown')}
      </p>
    </main>
  );
}
