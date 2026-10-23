import type { Metadata } from 'next';
import { SignupFlow } from '@/components/site/signup-flow';
import { SiteLayout } from '@/components/site/site-layout';
import { t } from '@/i18n';
import { loadPlans } from '@/server/plans';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('signup.title') };

export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ plano?: string }>;
}) {
  const { plano } = await searchParams;

  return (
    <SiteLayout>
      <div className="px-4 py-10">
        <SignupFlow plans={await loadPlans()} initialPlan={plano} />
      </div>
    </SiteLayout>
  );
}
