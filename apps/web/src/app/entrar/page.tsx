import type { Metadata } from 'next';
import { FindStudio } from '@/components/site/find-studio';
import { SiteLayout } from '@/components/site/site-layout';
import { t } from '@/i18n';

export const metadata: Metadata = { title: t('findStudio.title') };

export default function Page() {
  return (
    <SiteLayout>
      <div className="px-4 py-10">
        <FindStudio />
      </div>
    </SiteLayout>
  );
}
