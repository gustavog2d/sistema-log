import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { me } from './panel-fixtures';
import { AccountView } from './account-view';

const call = vi.fn();
const refresh = vi.fn();
const replace = vi.fn();
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => call(...args),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ refresh, replace, push: vi.fn() }),
}));

beforeEach(() => {
  call.mockReset();
  refresh.mockReset();
  replace.mockReset();
});

type User = ReturnType<typeof userEvent.setup>;

async function changePassword(
  user: User,
  current = 'senha-atual-123',
  next = 'outra-senha-longa-456',
) {
  await user.type(screen.getByLabelText(/^Senha atual/), current);
  await user.type(screen.getByLabelText(/^Nova senha/), next);
  await user.click(screen.getByRole('button', { name: 'Trocar a senha' }));
}

describe('AccountView: profile and password', () => {
  it('shows the data of the person', () => {
    render(<AccountView me={me('manager')} />);

    expect(screen.getByText('Ana Souza')).toBeInTheDocument();
    expect(screen.getByText('ana@exemplo.com')).toBeInTheDocument();
    expect(screen.getByText('Gerente')).toBeInTheDocument();
  });

  it('CA-11 changes the password and clears the fields, with the right autocomplete', async () => {
    call.mockResolvedValue({ status: 200, data: {} });
    const user = userEvent.setup();
    render(<AccountView me={me()} />);
    expect(screen.getByLabelText(/^Senha atual/)).toHaveAttribute(
      'autocomplete',
      'current-password',
    );
    expect(screen.getByLabelText(/^Nova senha/)).toHaveAttribute(
      'autocomplete',
      'new-password',
    );

    await changePassword(user);

    expect(await screen.findByText(/Senha trocada/)).toBeInTheDocument();
    expect(call).toHaveBeenCalledWith('POST', 'auth/password/change', {
      body: {
        current_password: 'senha-atual-123',
        password: 'outra-senha-longa-456',
      },
    });
    expect(screen.getByLabelText(/^Senha atual/)).toHaveValue('');
    expect(screen.getByLabelText(/^Nova senha/)).toHaveValue('');
  });

  it('CA-11 checks the new password on the screen first (12 characters, 72 bytes)', async () => {
    const user = userEvent.setup();
    render(<AccountView me={me()} />);

    await changePassword(user, 'senha-atual-123', 'curta');
    expect(
      screen.getAllByText('No mínimo 12 caracteres').length,
    ).toBeGreaterThan(0);
    await user.clear(screen.getByLabelText(/^Nova senha/));
    await user.type(screen.getByLabelText(/^Nova senha/), 'a'.repeat(73));
    await user.click(screen.getByRole('button', { name: 'Trocar a senha' }));

    expect(
      screen.getByText('A senha pode ter até 72 caracteres'),
    ).toBeInTheDocument();
    expect(call).not.toHaveBeenCalled();
  });

  it.each([
    [
      new ApiError('x', 422, {
        current_password: 'A senha atual está incorreta.',
      }),
      'A senha atual está incorreta.',
    ],
    [new ApiError('x', 423), /Muitas tentativas erradas/],
    [new ApiError('x', 429), /Muitas tentativas em pouco tempo/],
  ])('CA-11 shows the refusal of the API (%#)', async (error, text) => {
    call.mockRejectedValue(error);
    const user = userEvent.setup();
    render(<AccountView me={me()} />);

    await changePassword(user);

    expect(await screen.findByText(text)).toBeInTheDocument();
  });

  it('CA-15 never puts the password in the URL, storage or console', async () => {
    call.mockResolvedValue({ status: 200, data: {} });
    const log = vi.spyOn(console, 'log');
    sessionStorage.clear();
    localStorage.clear();
    const user = userEvent.setup();
    render(<AccountView me={me()} />);

    await changePassword(user);
    await screen.findByText(/Senha trocada/);

    expect(
      JSON.stringify([
        window.location.href,
        { ...localStorage },
        { ...sessionStorage },
        log.mock.calls,
      ]),
    ).not.toContain('senha-atual-123');
  });
});

describe('AccountView: two-factor', () => {
  it('CA-11 sets up 2fa: shows the secret, confirms with a code and shows the recovery codes once', async () => {
    call
      .mockResolvedValueOnce({
        status: 200,
        data: {
          secret: 'JBSWY3DPEHPK3PXP',
          otpauth_uri: 'otpauth://totp/Estudio:ana?secret=JBSWY3DPEHPK3PXP',
        },
      })
      .mockResolvedValueOnce({
        status: 200,
        data: { recovery_codes: ['aaaa-bbbb', 'cccc-dddd'] },
      });
    const user = userEvent.setup();
    render(<AccountView me={me()} />);

    await user.click(
      screen.getByRole('button', { name: 'Ligar a verificação' }),
    );
    expect(await screen.findByTestId('two-factor-secret')).toHaveTextContent(
      'JBSWY3DPEHPK3PXP',
    );
    await user.type(screen.getByLabelText(/^Código de 6 dígitos/), '123456');
    await user.click(screen.getByRole('button', { name: 'Confirmar e ligar' }));

    expect(await screen.findByText('aaaa-bbbb')).toBeInTheDocument();
    expect(screen.getByText(/não será mostrado de novo/)).toBeInTheDocument();
    expect(call).toHaveBeenLastCalledWith('POST', 'auth/2fa/confirm', {
      body: { code: '123456' },
    });
    await user.click(
      screen.getByRole('button', { name: 'Guardei os códigos' }),
    );
    expect(refresh).toHaveBeenCalled();
  });

  it('CA-11 shows a wrong first code and keeps the secret on screen', async () => {
    call
      .mockResolvedValueOnce({
        status: 200,
        data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'x' },
      })
      .mockRejectedValueOnce(new ApiError('x', 422));
    const user = userEvent.setup();
    render(<AccountView me={me()} />);
    await user.click(
      screen.getByRole('button', { name: 'Ligar a verificação' }),
    );
    await screen.findByTestId('two-factor-secret');

    await user.type(screen.getByLabelText(/^Código de 6 dígitos/), '000000');
    await user.click(screen.getByRole('button', { name: 'Confirmar e ligar' }));

    expect(await screen.findByText(/Código incorreto/)).toBeInTheDocument();
    expect(screen.getByTestId('two-factor-secret')).toBeInTheDocument();
  });

  it('CA-11 turns 2fa off after confirming with the password', async () => {
    call.mockResolvedValue({ status: 200, data: {} });
    const user = userEvent.setup();
    render(<AccountView me={me('owner', { two_factor_enabled: true })} />);
    expect(screen.getByText('Ligada')).toBeInTheDocument();

    await user.click(
      screen.getByRole('button', { name: 'Desligar a verificação' }),
    );
    await user.type(
      screen.getAllByLabelText(/^Senha atual/)[1]!,
      'minha-senha-123',
    );
    await user.click(screen.getByRole('button', { name: 'Desligar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'auth/2fa/disable', {
        body: { current_password: 'minha-senha-123' },
      }),
    );
    expect(
      await screen.findByText('Verificação desligada.'),
    ).toBeInTheDocument();
    expect(refresh).toHaveBeenCalled();
  });

  it('CA-11 asks for the password before turning 2fa off and shows a wrong one', async () => {
    call.mockRejectedValue(new ApiError('x', 422, { current_password: 'x' }));
    const user = userEvent.setup();
    render(<AccountView me={me('owner', { two_factor_enabled: true })} />);
    await user.click(
      screen.getByRole('button', { name: 'Desligar a verificação' }),
    );

    await user.click(screen.getByRole('button', { name: 'Desligar' }));
    expect(call).not.toHaveBeenCalled();

    await user.type(
      screen.getAllByLabelText(/^Senha atual/)[1]!,
      'errada-12345678',
    );
    await user.click(screen.getByRole('button', { name: 'Desligar' }));
    expect(
      await screen.findByText('A senha atual está incorreta.'),
    ).toBeInTheDocument();
  });
});

describe('AccountView: sessions and support access', () => {
  it('CA-11 signs out of this session and of all of them, going to the login', async () => {
    call.mockResolvedValue({ status: 204, data: null });
    const user = userEvent.setup();
    render(<AccountView me={me()} />);

    await user.click(screen.getByRole('button', { name: 'Sair' }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith('/entrar'));
    expect(call).toHaveBeenLastCalledWith('POST', 'auth/logout');

    await user.click(
      screen.getByRole('button', { name: 'Sair de todos os acessos' }),
    );
    await waitFor(() =>
      expect(call).toHaveBeenLastCalledWith('POST', 'auth/logout-all'),
    );
  });

  it('CA-14 blocks password, 2fa and sign out of all in a support access', () => {
    render(<AccountView me={me('owner', { impersonating: true })} />);

    expect(
      screen.getByText('Indisponível em um acesso de suporte.'),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/^Senha atual/)).toBeDisabled();
    expect(
      screen.getByRole('button', { name: 'Trocar a senha' }),
    ).toBeDisabled();
    expect(
      screen.queryByRole('button', { name: 'Ligar a verificação' }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Sair de todos os acessos' }),
    ).toBeDisabled();
  });

  it('CA-16 has no accessibility violations (off, on, support access, with the setup open)', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://x' },
    });
    const user = userEvent.setup();
    const { container, rerender } = render(<AccountView me={me()} />);
    expect(await axe(container)).toHaveNoViolations();

    await user.click(
      screen.getByRole('button', { name: 'Ligar a verificação' }),
    );
    await screen.findByTestId('two-factor-secret');
    expect(await axe(container)).toHaveNoViolations();

    rerender(<AccountView me={me('owner', { two_factor_enabled: true })} />);
    expect(await axe(container)).toHaveNoViolations();

    rerender(<AccountView me={me('owner', { impersonating: true })} />);
    expect(await axe(container)).toHaveNoViolations();
  });
});
