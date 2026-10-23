import type { Metadata } from 'next';
import { LoadError, NoPermission } from '@/components/panel/notices';
import { TeamView } from '@/components/panel/team-view';
import { t } from '@/i18n';
import {
  canSee,
  type TeamInvitation,
  type TeamMember,
} from '@/lib/panel-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const metadata: Metadata = { title: t('team.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') return null;
  if (!canSee(session.me.user.role, 'team')) return <NoPermission />;

  const team = await apiGet<{
    members: TeamMember[];
    invitations: TeamInvitation[];
  }>('users', await getPanelContext());

  if (!team.data) return <LoadError />;

  return (
    <TeamView
      me={session.me}
      members={team.data.members}
      invitations={team.data.invitations}
      canWrite={session.subscription?.can_write ?? false}
    />
  );
}
