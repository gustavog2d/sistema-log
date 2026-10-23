'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { t, tf } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { formatDate } from '@/lib/format';
import type { Me, Role, TeamInvitation, TeamMember } from '@/lib/panel-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog } from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { SelectField, TextField } from '@/components/ui/inputs';
import { Table, Td, Th, Tr } from '@/components/ui/table';

const ASSIGNABLE: Array<Exclude<Role, 'owner'>> = [
  'manager',
  'professional',
  'reception',
];

type Dialogs =
  | { kind: 'invite' }
  | { kind: 'role'; member: TeamMember }
  | { kind: 'deactivate'; member: TeamMember }
  | null;

/** Equipe do negócio (dona e gerente): pessoas e convites, com as ações que a API permite. */
export function TeamView({
  me,
  members,
  invitations,
  canWrite,
}: {
  me: Me;
  members: TeamMember[];
  invitations: TeamInvitation[];
  canWrite: boolean;
}) {
  const router = useRouter();
  const [dialog, setDialog] = useState<Dialogs>(null);
  const [notice, setNotice] = useState<{
    tone: 'success' | 'danger';
    text: string;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({
    name: '',
    email: '',
    role: 'professional' as Exclude<Role, 'owner'>,
  });

  const disabled = !canWrite || me.impersonating;

  function open(next: Dialogs) {
    setError(null);
    setFieldErrors({});
    setDialog(next);

    if (next?.kind === 'role') {
      setForm((current) => ({
        ...current,
        role: next.member.role === 'owner' ? 'professional' : next.member.role,
      }));
    }
  }

  function explain(failure: unknown): string {
    if (failure instanceof ApiError && failure.code === 'plan_limit_reached')
      return t('team.limit');
    if (failure instanceof ApiError && failure.code === 'already_member')
      return t('team.alreadyMember');

    return failure instanceof ApiError
      ? failure.message
      : t('common.genericError');
  }

  async function run(
    work: () => Promise<unknown>,
    success: string,
    closeOnError = false,
  ): Promise<boolean> {
    setBusy(true);
    setError(null);
    setFieldErrors({});

    try {
      await work();
      setDialog(null);
      setNotice({ tone: 'success', text: success });
      router.refresh();

      return true;
    } catch (failure) {
      if (
        failure instanceof ApiError &&
        Object.keys(failure.fieldErrors).length > 0
      ) {
        setFieldErrors(failure.fieldErrors);
      } else if (closeOnError) {
        setDialog(null);
        setNotice({ tone: 'danger', text: explain(failure) });
      } else {
        setError(explain(failure));
      }

      return false;
    } finally {
      setBusy(false);
    }
  }

  function invite(event: FormEvent) {
    event.preventDefault();
    void run(
      () =>
        call('POST', 'users/invitations', {
          body: {
            name: form.name.trim(),
            email: form.email.trim(),
            role: form.role,
          },
        }),
      tf('team.inviteSent', { email: form.email.trim() }),
    ).then((ok) => {
      if (ok) setForm((current) => ({ ...current, name: '', email: '' }));
    });
  }

  const roleOptions = (
    <>
      {ASSIGNABLE.map((role) => (
        <option key={role} value={role}>
          {t(`roles.${role}`)}
        </option>
      ))}
    </>
  );

  return (
    <div className="space-y-6">
      <PageHeader
        title={t('team.title')}
        actions={
          <Button disabled={disabled} onClick={() => open({ kind: 'invite' })}>
            {t('team.invite')}
          </Button>
        }
      />
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}
      {me.impersonating ? (
        <Alert tone="info">{t('team.blockedSupport')}</Alert>
      ) : !canWrite ? (
        <Alert tone="warning">{t('team.readOnly')}</Alert>
      ) : null}

      <div>
        <h2 id="pessoas" className="mb-3 text-lg font-semibold text-ink">
          {t('team.people')}
        </h2>
        {members.length <= 1 ? (
          <div className="mb-3">
            <EmptyState title={t('team.empty')} />
          </div>
        ) : null}
        <Table caption={t('team.peopleCaption')}>
          <thead>
            <Tr>
              <Th>{t('team.name')}</Th>
              <Th>{t('team.email')}</Th>
              <Th>{t('team.role')}</Th>
              <Th>{t('team.situation')}</Th>
              <Th>{t('team.actions')}</Th>
            </Tr>
          </thead>
          <tbody>
            {members.map((member) => {
              const self = member.id === me.user.id;
              const actionable = member.role !== 'owner' && !self;

              return (
                <Tr key={member.id}>
                  <Td>
                    {member.name}{' '}
                    {self ? (
                      <span className="text-muted">{t('team.you')}</span>
                    ) : null}
                  </Td>
                  <Td>{member.email}</Td>
                  <Td>{t(`roles.${member.role}`)}</Td>
                  <Td>
                    <Badge
                      tone={member.status === 'active' ? 'success' : 'neutral'}
                    >
                      {t(
                        member.status === 'active'
                          ? 'team.active'
                          : 'team.inactive',
                      )}
                    </Badge>
                  </Td>
                  <Td>
                    {actionable ? (
                      <span className="flex flex-wrap gap-2">
                        <Button
                          variant="secondary"
                          disabled={disabled}
                          onClick={() => open({ kind: 'role', member })}
                          aria-label={`${t('team.changeRole')}: ${member.name}`}
                        >
                          {t('team.changeRole')}
                        </Button>
                        {member.status === 'active' ? (
                          <Button
                            variant="secondary"
                            disabled={disabled}
                            onClick={() => open({ kind: 'deactivate', member })}
                            aria-label={`${t('team.deactivate')}: ${member.name}`}
                          >
                            {t('team.deactivate')}
                          </Button>
                        ) : (
                          <Button
                            variant="secondary"
                            disabled={disabled || busy}
                            onClick={() =>
                              void run(
                                () =>
                                  call('POST', `users/${member.id}/reactivate`),
                                t('team.reactivated'),
                                true,
                              )
                            }
                            aria-label={`${t('team.reactivate')}: ${member.name}`}
                          >
                            {t('team.reactivate')}
                          </Button>
                        )}
                      </span>
                    ) : null}
                  </Td>
                </Tr>
              );
            })}
          </tbody>
        </Table>
      </div>

      {invitations.length > 0 ? (
        <div>
          <h2 id="convites" className="mb-3 text-lg font-semibold text-ink">
            {t('team.invitations')}
          </h2>
          <Table caption={t('team.invitationsCaption')}>
            <thead>
              <Tr>
                <Th>{t('team.name')}</Th>
                <Th>{t('team.email')}</Th>
                <Th>{t('team.role')}</Th>
                <Th>{t('team.situation')}</Th>
                <Th>{t('team.actions')}</Th>
              </Tr>
            </thead>
            <tbody>
              {invitations.map((invitation) => (
                <Tr key={invitation.id}>
                  <Td>{invitation.name}</Td>
                  <Td>{invitation.email}</Td>
                  <Td>{t(`roles.${invitation.role}`)}</Td>
                  <Td>
                    {tf('team.validUntil', {
                      date: formatDate(invitation.expires_at),
                    })}
                  </Td>
                  <Td>
                    <Button
                      variant="secondary"
                      disabled={disabled || busy}
                      onClick={() =>
                        void run(
                          () =>
                            call(
                              'DELETE',
                              `users/invitations/${invitation.id}`,
                            ),
                          t('team.inviteCanceled'),
                          true,
                        )
                      }
                      aria-label={`${t('team.cancelInvite')}: ${invitation.email}`}
                    >
                      {t('team.cancelInvite')}
                    </Button>
                  </Td>
                </Tr>
              ))}
            </tbody>
          </Table>
        </div>
      ) : null}

      <Dialog
        open={dialog?.kind === 'invite'}
        onClose={() => setDialog(null)}
        title={t('team.inviteTitle')}
        description={t('team.inviteText')}
      >
        <form onSubmit={invite} noValidate className="flex flex-col gap-4">
          {error ? <Alert tone="danger">{error}</Alert> : null}
          <TextField
            label={t('team.inviteName')}
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            error={fieldErrors.name}
            autoComplete="off"
            required
          />
          <TextField
            label={t('team.inviteEmail')}
            type="email"
            value={form.email}
            onChange={(event) =>
              setForm({ ...form, email: event.target.value })
            }
            error={fieldErrors.email}
            autoComplete="off"
            required
          />
          <SelectField
            label={t('team.inviteRole')}
            value={form.role}
            onChange={(event) =>
              setForm({
                ...form,
                role: event.target.value as Exclude<Role, 'owner'>,
              })
            }
            error={fieldErrors.role}
          >
            {roleOptions}
          </SelectField>
          <div className="flex justify-end gap-3">
            <Button variant="secondary" onClick={() => setDialog(null)}>
              {t('common.cancel')}
            </Button>
            <Button type="submit" loading={busy}>
              {t('team.inviteSend')}
            </Button>
          </div>
        </form>
      </Dialog>

      <Dialog
        open={dialog?.kind === 'role'}
        onClose={() => setDialog(null)}
        title={
          dialog?.kind === 'role'
            ? tf('team.changeRoleTitle', { name: dialog.member.name })
            : ''
        }
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              loading={busy}
              onClick={() =>
                dialog?.kind === 'role' &&
                void run(
                  () =>
                    call('PATCH', `users/${dialog.member.id}`, {
                      body: { role: form.role },
                    }),
                  t('team.roleChanged'),
                )
              }
            >
              {t('team.save')}
            </Button>
          </>
        }
      >
        {error ? (
          <Alert tone="danger" className="mb-3">
            {error}
          </Alert>
        ) : null}
        <SelectField
          label={t('team.role')}
          value={form.role}
          onChange={(event) =>
            setForm({
              ...form,
              role: event.target.value as Exclude<Role, 'owner'>,
            })
          }
          error={fieldErrors.role}
        >
          {roleOptions}
        </SelectField>
      </Dialog>

      <Dialog
        open={dialog?.kind === 'deactivate'}
        onClose={() => setDialog(null)}
        title={
          dialog?.kind === 'deactivate'
            ? tf('team.deactivateTitle', { name: dialog.member.name })
            : ''
        }
        description={t('team.deactivateBody')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDialog(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={busy}
              onClick={() =>
                dialog?.kind === 'deactivate' &&
                void run(
                  () => call('POST', `users/${dialog.member.id}/deactivate`),
                  t('team.deactivated'),
                  true,
                )
              }
            >
              {t('team.deactivateConfirm')}
            </Button>
          </>
        }
      />
    </div>
  );
}
