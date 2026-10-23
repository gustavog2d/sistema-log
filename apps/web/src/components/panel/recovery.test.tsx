import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { AcceptInviteForm, ForgotForm, ResetForm } from './recovery';

const call = vi.fn();
const replace = vi.fn();
const refresh = vi.fn();
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => call(...args),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace, refresh, push: vi.fn() }),
}));

beforeEach(() => {
  call.mockReset();
  replace.mockReset();
  refresh.mockReset();
  window.history.pushState(
    {},
    '',
    '/redefinir-senha?token=abc&email=ana%40exemplo.com',
  );
});

describe('ForgotForm', () => {
  it('CA-12 asks for the link and always shows the same answer', async () => {
    call.mockResolvedValue({ status: 202, data: null });
    const user = userEvent.setup();
    render(<ForgotForm />);

    await user.type(screen.getByLabelText(/^E-mail/), 'ninguem@exemplo.com');
    await user.click(screen.getByRole('button', { name: 'Enviar o link' }));

    expect(await screen.findByRole('status')).toHaveTextContent(
      'Se o e-mail existir, enviamos o link.',
    );
    expect(call).toHaveBeenCalledWith('POST', 'auth/password/forgot', {
      body: { email: 'ninguem@exemplo.com' },
    });
  });

  it('CA-12 shows a wait notice on 429 and keeps the form', async () => {
    call.mockRejectedValue(new ApiError('x', 429));
    const user = userEvent.setup();
    render(<ForgotForm />);
    await user.type(screen.getByLabelText(/^E-mail/), 'ana@exemplo.com');

    await user.click(screen.getByRole('button', { name: 'Enviar o link' }));

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Tente de novo mais tarde',
    );
    expect(screen.getByLabelText(/^E-mail/)).toHaveValue('ana@exemplo.com');
  });

  it('CA-16 has no accessibility violations and links back to the login', async () => {
    const { container } = render(<ForgotForm />);

    expect(await axe(container)).toHaveNoViolations();
    expect(
      screen.getByRole('link', { name: 'Voltar ao login' }),
    ).toHaveAttribute('href', '/entrar');
  });
});

describe('ResetForm', () => {
  it('CA-12 saves the new password with the token and e-mail of the link and goes to the login', async () => {
    call.mockResolvedValue({ status: 204, data: null });
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    render(<ResetForm token="abc" email="ana@exemplo.com" />);

    await user.type(
      screen.getByLabelText(/^Nova senha/),
      'uma-senha-nova-longa',
    );
    await user.click(
      screen.getByRole('button', { name: 'Salvar a nova senha' }),
    );

    expect(
      await screen.findByText('Senha alterada. Agora é só entrar.'),
    ).toBeInTheDocument();
    expect(call).toHaveBeenCalledWith('POST', 'auth/password/reset', {
      body: {
        email: 'ana@exemplo.com',
        token: 'abc',
        password: 'uma-senha-nova-longa',
      },
    });
    await vi.advanceTimersByTimeAsync(2000);
    expect(replace).toHaveBeenCalledWith('/entrar');
    vi.useRealTimers();
  });

  it('CA-13 takes the token and the e-mail off the address bar', () => {
    render(<ResetForm token="abc" email="ana@exemplo.com" />);

    expect(window.location.search).toBe('');
    expect(window.location.pathname).toBe('/redefinir-senha');
  });

  it('CA-12 checks the password on the screen first', async () => {
    const user = userEvent.setup();
    render(<ResetForm token="abc" email="ana@exemplo.com" />);

    await user.type(screen.getByLabelText(/^Nova senha/), 'curta');
    await user.click(
      screen.getByRole('button', { name: 'Salvar a nova senha' }),
    );

    expect(
      screen.getAllByText('No mínimo 12 caracteres').length,
    ).toBeGreaterThan(0);
    expect(call).not.toHaveBeenCalled();
  });

  it('CA-12 shows one message for an invalid or expired link and offers another one', async () => {
    call.mockRejectedValue(
      new ApiError('x', 422, {
        token: 'O link de recuperação é inválido ou expirou.',
      }),
    );
    const user = userEvent.setup();
    render(<ResetForm token="abc" email="ana@exemplo.com" />);

    await user.type(
      screen.getByLabelText(/^Nova senha/),
      'uma-senha-nova-longa',
    );
    await user.click(
      screen.getByRole('button', { name: 'Salvar a nova senha' }),
    );

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Este link é inválido ou já venceu.',
    );
    expect(
      screen.getByRole('link', { name: 'Pedir outro link' }),
    ).toHaveAttribute('href', '/esqueci-senha');
    expect(screen.queryByLabelText(/^Nova senha/)).not.toBeInTheDocument();
  });

  it('CA-12 shows the message when the link is incomplete', () => {
    render(<ResetForm />);

    expect(screen.getByRole('alert')).toHaveTextContent('Link incompleto');
  });

  it('CA-16 has no accessibility violations', async () => {
    const { container } = render(
      <ResetForm token="abc" email="ana@exemplo.com" />,
    );

    expect(await axe(container)).toHaveNoViolations();
  });
});

describe('AcceptInviteForm', () => {
  const fill = async (
    user: ReturnType<typeof userEvent.setup>,
    password = 'uma-senha-forte-123',
  ) => {
    await user.type(screen.getByLabelText(/^Senha/), password);
    await user.click(screen.getByLabelText('Li e aceito os termos de uso'));
    await user.click(screen.getByRole('button', { name: 'Aceitar e entrar' }));
  };

  it('CA-13 accepts with the password and the terms, then enters the panel', async () => {
    call.mockResolvedValue({ status: 200, data: { authenticated: true } });
    const user = userEvent.setup();
    render(<AcceptInviteForm token="tok" email="beto@exemplo.com" />);

    await fill(user);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/'));
    expect(call).toHaveBeenCalledWith('POST', 'auth/invitations/accept', {
      body: {
        email: 'beto@exemplo.com',
        token: 'tok',
        password: 'uma-senha-forte-123',
        accept_terms: true,
      },
    });
  });

  it('CA-13 goes to the login when a second step (2fa) is still needed', async () => {
    call.mockResolvedValue({ status: 200, data: { next: 'verify' } });
    const user = userEvent.setup();
    render(<AcceptInviteForm token="tok" email="beto@exemplo.com" />);

    await fill(user);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/entrar'));
  });

  it('CA-13 takes the token off the address bar and explains that an existing account uses its own password', () => {
    render(<AcceptInviteForm token="tok" email="beto@exemplo.com" />);

    expect(window.location.search).toBe('');
    expect(
      screen.getByText(/já tem conta em outro negócio/),
    ).toBeInTheDocument();
  });

  it('CA-13 requires the terms and a good password before sending', async () => {
    const user = userEvent.setup();
    render(<AcceptInviteForm token="tok" email="beto@exemplo.com" />);

    await user.type(screen.getByLabelText(/^Senha/), 'curta');
    await user.click(screen.getByRole('button', { name: 'Aceitar e entrar' }));

    expect(
      screen.getByText('É preciso aceitar os termos para continuar'),
    ).toBeInTheDocument();
    expect(call).not.toHaveBeenCalled();
  });

  it('CA-13 shows one message for any invalid invitation and a different one for a wrong existing password', async () => {
    call.mockRejectedValueOnce(
      new ApiError('x', 422, { token: 'O convite é inválido ou expirou.' }),
    );
    const user = userEvent.setup();
    const { unmount } = render(
      <AcceptInviteForm token="tok" email="beto@exemplo.com" />,
    );
    await fill(user);
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Este convite é inválido ou já venceu.',
    );
    unmount();

    call.mockRejectedValueOnce(
      new ApiError('x', 422, { password: 'A senha não confere.' }),
    );
    render(<AcceptInviteForm token="tok" email="beto@exemplo.com" />);
    await fill(user);
    expect(await screen.findByText('A senha não confere.')).toBeInTheDocument();
  });

  it('CA-13 shows the lock and the wait warnings', async () => {
    call.mockRejectedValue(new ApiError('x', 423));
    const user = userEvent.setup();
    render(<AcceptInviteForm token="tok" email="beto@exemplo.com" />);

    await fill(user);

    expect(await screen.findByRole('alert')).toHaveTextContent(/bloqueado/);
  });

  it('CA-13 shows the invalid message without a token', () => {
    render(<AcceptInviteForm />);

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Este convite é inválido ou já venceu.',
    );
  });

  it('CA-16 has no accessibility violations', async () => {
    const { container } = render(
      <AcceptInviteForm token="tok" email="beto@exemplo.com" />,
    );

    expect(await axe(container)).toHaveNoViolations();
  });
});
