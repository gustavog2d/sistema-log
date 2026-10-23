import { redirect } from 'next/navigation';
import { HomeView } from '@/components/panel/home-view';
import { getPanelSession } from '@/server/panel-session';

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');

  return <HomeView me={session.me} subscription={session.subscription} />;
}
