import { SignupDone } from '@/components/site/signup-done';
import { SiteLayout } from '@/components/site/site-layout';

export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ negocio?: string; fim?: string }>;
}) {
  const { negocio, fim } = await searchParams;

  return (
    <SiteLayout>
      <div className="px-4 py-10">
        <SignupDone slug={negocio} trialEndsAt={fim} />
      </div>
    </SiteLayout>
  );
}
