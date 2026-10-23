import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { INVITATIONS, MEMBERS, me } from './panel-fixtures';
import { TeamView } from './team-view';

const call = vi.fn();
const refresh = vi.fn();
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => call(...args),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ refresh, replace: vi.fn(), push: vi.fn() }),
}));

beforeEach(() => {
  call.mockReset();
  refresh.mockReset();
  call.mockResolvedValue({ status: 200, data: {} });
});

const view = (props: Partial<React.ComponentProps<typeof TeamView>> = {}) => (
  <TeamView
    me={me()}
    members={MEMBERS}
    invitations={INVITATIONS}
    canWrite
    {...props}
  />
);

async function openInvite(user: ReturnType<typeof userEvent.setup>) {
  await user.click(screen.getByRole('button', { name: 'Convidar pessoa' }));
}

describe('TeamView', () => {
  it('CA-09 lists the people and the pending invitations, with status and validity', () => {
    render(view());
    const people = screen.getByRole('table', { name: 'Pessoas da equipe' });
    const invites = screen.getByRole('table', { name: 'Convites pendentes' });

    expect(within(people).getByText('Beto Lima')).toBeInTheDocument();
    expect(within(people).getByText('Desativada')).toBeInTheDocument();
    expect(within(people).getByText('(você)')).toBeInTheDocument();
    expect(within(invites).getByText('dani@exemplo.com')).toBeInTheDocument();
    expect(
      within(invites).getByText('Válido até 01/11/2026'),
    ).toBeInTheDocument();
  });

  it('CA-09 gives the owner and yourself no actions', () => {
    render(view());
    const rows = within(
      screen.getByRole('table', { name: 'Pessoas da equipe' }),
    ).getAllByRole('row');

    expect(within(rows[1]!).queryAllByRole('button')).toHaveLength(0);
    expect(within(rows[2]!).getAllByRole('button')).toHaveLength(2);
  });

  it('CA-09 invites with name, e-mail and role, and shows the confirmation', async () => {
    const user = userEvent.setup();
    render(view());
    await openInvite(user);

    await user.type(screen.getByLabelText(/^Nome/), 'Eva Nunes');
    await user.type(screen.getByLabelText(/^E-mail/), 'eva@exemplo.com');
    await user.selectOptions(screen.getByLabelText(/^Papel/), 'reception');
    await user.click(screen.getByRole('button', { name: 'Enviar convite' }));

    await waitFor(() => expect(refresh).toHaveBeenCalled());
    expect(call).toHaveBeenCalledWith('POST', 'users/invitations', {
      body: { name: 'Eva Nunes', email: 'eva@exemplo.com', role: 'reception' },
    });
    expect(screen.getByRole('status')).toHaveTextContent(
      'Convite enviado para eva@exemplo.com.',
    );
  });

  it('CA-09 does not offer the owner role in an invitation', async () => {
    const user = userEvent.setup();
    render(view());
    await openInvite(user);

    const options = within(screen.getByLabelText(/^Papel/))
      .getAllByRole('option')
      .map((o) => o.textContent);
    expect(options).toEqual(['Gerente', 'Profissional', 'Recepção']);
  });

  it('CA-09 shows API field errors on the fields and keeps the dialog open', async () => {
    call.mockRejectedValue(
      new ApiError('x', 422, { email: 'O e-mail é inválido.' }),
    );
    const user = userEvent.setup();
    render(view());
    await openInvite(user);
    await user.type(screen.getByLabelText(/^Nome/), 'Eva');
    await user.type(screen.getByLabelText(/^E-mail/), 'eva@exemplo.com');

    await user.click(screen.getByRole('button', { name: 'Enviar convite' }));

    expect(await screen.findByText('O e-mail é inválido.')).toBeInTheDocument();
    expect(screen.getByLabelText(/^E-mail/)).toHaveAttribute(
      'aria-invalid',
      'true',
    );
    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });

  it('CA-10 explains the plan limit and the case of someone who is already on the team', async () => {
    call
      .mockRejectedValueOnce(new ApiError('x', 409, {}, 'plan_limit_reached'))
      .mockRejectedValueOnce(new ApiError('x', 409, {}, 'already_member'));
    const user = userEvent.setup();
    render(view());
    await openInvite(user);
    await user.type(screen.getByLabelText(/^Nome/), 'Eva');
    await user.type(screen.getByLabelText(/^E-mail/), 'eva@exemplo.com');

    await user.click(screen.getByRole('button', { name: 'Enviar convite' }));
    expect(
      await screen.findByText(/limite de usuários do plano foi atingido/),
    ).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Enviar convite' }));
    expect(
      await screen.findByText('Esta pessoa já faz parte da equipe.'),
    ).toBeInTheDocument();
  });

  it('CA-09 changes a role through a dialog', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Trocar papel: Beto Lima' }),
    );
    const dialog = screen.getByRole('dialog', {
      name: 'Trocar o papel de Beto Lima',
    });
    expect(within(dialog).getByLabelText(/^Papel/)).toHaveValue('professional');
    await user.selectOptions(
      within(dialog).getByLabelText(/^Papel/),
      'manager',
    );
    await user.click(within(dialog).getByRole('button', { name: 'Salvar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('PATCH', 'users/u-2', {
        body: { role: 'manager' },
      }),
    );
    expect(await screen.findByText('Papel atualizado.')).toBeInTheDocument();
  });

  it('CA-09 asks for confirmation before deactivating and says what happens', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Desativar: Beto Lima' }),
    );
    const dialog = screen.getByRole('dialog', { name: 'Desativar Beto Lima?' });
    expect(dialog).toHaveAccessibleDescription(/perde o acesso na hora/);
    await user.click(
      within(dialog).getByRole('button', { name: 'Sim, desativar' }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'users/u-2/deactivate'),
    );
    expect(await screen.findByText('Pessoa desativada.')).toBeInTheDocument();
  });

  it('CA-09 reactivates a deactivated person and cancels an invitation with one click', async () => {
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Reativar: Cris Dias' }),
    );
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'users/u-3/reactivate'),
    );

    await user.click(
      screen.getByRole('button', {
        name: 'Cancelar convite: dani@exemplo.com',
      }),
    );
    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('DELETE', 'users/invitations/inv-1'),
    );
  });

  it('CA-09 shows the API refusal as a notice when a one-click action fails', async () => {
    call.mockRejectedValue(new ApiError('Esta ação não é permitida.', 403));
    const user = userEvent.setup();
    render(view());

    await user.click(
      screen.getByRole('button', { name: 'Reativar: Cris Dias' }),
    );

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Esta ação não é permitida.',
    );
  });

  it('CA-05 disables every change when the subscription is read-only and says why', () => {
    render(view({ canWrite: false }));

    expect(screen.getByRole('alert')).toHaveTextContent(/só para consulta/);
    expect(
      screen.getByRole('button', { name: 'Convidar pessoa' }),
    ).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Trocar papel: Beto Lima' }),
    ).toBeDisabled();
    expect(
      screen.getByRole('button', {
        name: 'Cancelar convite: dani@exemplo.com',
      }),
    ).toBeDisabled();
  });

  it('CA-14 disables the changes in a support access', () => {
    render(view({ me: me('owner', { impersonating: true }) }));

    expect(
      screen.getByText(/não estão disponíveis em um acesso de suporte/),
    ).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Convidar pessoa' }),
    ).toBeDisabled();
  });

  it('shows an empty state when the owner is alone', () => {
    render(view({ members: [MEMBERS[0]!], invitations: [] }));

    expect(
      screen.getByText('Só você por enquanto. Convide a sua equipe.'),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('table', { name: 'Convites pendentes' }),
    ).not.toBeInTheDocument();
  });

  it('CA-16 has no accessibility violations (list, invite dialog and read-only)', async () => {
    const user = userEvent.setup();
    const { container } = render(view());
    expect(await axe(container)).toHaveNoViolations();

    await openInvite(user);
    expect(await axe(container)).toHaveNoViolations();
  });
});
