import { Legal } from '@/components/site/legal';
import { SiteLayout } from '@/components/site/site-layout';

export default function Page() {
  return (
    <SiteLayout>
      <Legal kind="terms" />
    </SiteLayout>
  );
}
