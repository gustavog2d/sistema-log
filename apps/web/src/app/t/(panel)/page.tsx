import { HomeView } from '@/components/panel/home-view';
import { getPanelSession } from '@/server/panel-session';

export default async function Page() {
  const session = await getPanelSession();

  return session.kind === 'ok' ? (
    <HomeView me={session.me} subscription={session.subscription} />
  ) : null;
}
