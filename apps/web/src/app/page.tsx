import { Landing } from '@/components/site/landing';
import { SiteLayout } from '@/components/site/site-layout';
import { loadPlans } from '@/server/plans';

export const dynamic = 'force-dynamic';

export default async function Page() {
  return (
    <SiteLayout>
      <Landing
        plans={await loadPlans()}
        highlight={process.env.NEXT_PUBLIC_HIGHLIGHT_PLAN}
      />
    </SiteLayout>
  );
}
