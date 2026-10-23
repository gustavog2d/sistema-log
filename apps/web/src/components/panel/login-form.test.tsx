import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { LoginForm } from './login-form';

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
});

async function fill(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/^E-mail/), 'ana@exemplo.com');
  await user.type(screen.getByLabelText(/^Senha/), 'uma-senha-forte-123');
  await user.click(screen.getByRole('button', { name: 'Entrar' }));
}

describe('LoginForm', () => {
  it('CA-01 signs in and goes to the start page, sending the e-mail and password only in the body', async () => {
    call.mockResolvedValue({ status: 200, data: { authenticated: true } });
    const user = userEvent.setup();
    render(<LoginForm />);

    await fill(user);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/'));
    expect(refresh).toHaveBeenCalled();
    expect(call).toHaveBeenCalledWith('POST', 'auth/login', {
      body: { email: 'ana@exemplo.com', password: 'uma-senha-forte-123' },
    });
    expect(window.location.href).not.toContain('senha');
  });

  it('CA-01 goes back to the page that was asked for, but only an internal one', async () => {
    call.mockResolvedValue({ status: 200, data: { authenticated: true } });
    const user = userEvent.setup();
    const { unmount } = render(<LoginForm returnTo="/equipe" />);
    await fill(user);
    await waitFor(() => expect(replace).toHaveBeenCalledWith('/equipe'));
    unmount();

    replace.mockReset();
    render(<LoginForm returnTo="https://evil.com" />);
    await fill(user);
    await waitFor(() => expect(replace).toHaveBeenCalledWith('/'));
  });

  it('CA-01 shows one message for any wrong credential', async () => {
    call.mockRejectedValue(new ApiError('Unauthorized', 401));
    const user = userEvent.setup();
    render(<LoginForm />);

    await fill(user);

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'E-mail ou senha incorretos.',
    );
    expect(replace).not.toHaveBeenCalled();
    expect(screen.getByLabelText(/^E-mail/)).toHaveValue('ana@exemplo.com');
  });

  it.each([
    [423, /bloqueado/],
    [429, /Muitas tentativas em pouco tempo/],
  ])('CA-01 shows the right warning for %s', async (status, text) => {
    call.mockRejectedValue(new ApiError('x', status));
    const user = userEvent.setup();
    render(<LoginForm />);

    await fill(user);

    expect(await screen.findByRole('alert')).toHaveTextContent(text);
  });

  it('CA-01 shows a general message for a network failure', async () => {
    call.mockRejectedValue(
      new ApiError('Não foi possível falar com o servidor. Tente de novo.', 0),
    );
    const user = userEvent.setup();
    render(<LoginForm />);

    await fill(user);

    expect(await screen.findByRole('alert')).toHaveTextContent('servidor');
  });

  it('CA-01 is busy while signing in so a double click sends once', async () => {
    let resolve!: (value: unknown) => void;
    call.mockReturnValue(new Promise((done) => (resolve = done)));
    const user = userEvent.setup();
    render(<LoginForm />);
    await fill(user);

    const busy = await screen.findByRole('button', { name: /Entrando/ });
    await user.click(busy);

    expect(call).toHaveBeenCalledTimes(1);
    resolve({ status: 200, data: { authenticated: true } });
  });

  it('CA-02 asks for the code when the account has 2fa, then signs in', async () => {
    call
      .mockResolvedValueOnce({ status: 200, data: { next: 'verify' } })
      .mockResolvedValueOnce({ status: 200, data: { authenticated: true } });
    const user = userEvent.setup();
    render(<LoginForm />);

    await fill(user);
    expect(
      await screen.findByRole('heading', { name: 'Confirmar o código' }),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText(/^Senha/)).not.toBeInTheDocument();
    await user.type(screen.getByLabelText(/^Código/), '123456');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/'));
    expect(call).toHaveBeenLastCalledWith('POST', 'auth/2fa/verify', {
      body: { code: '123456' },
    });
  });

  it('CA-02 shows the same message for a wrong code and accepts a recovery code', async () => {
    call
      .mockResolvedValueOnce({ status: 200, data: { next: 'verify' } })
      .mockRejectedValueOnce(new ApiError('x', 401))
      .mockResolvedValueOnce({ status: 200, data: { authenticated: true } });
    const user = userEvent.setup();
    render(<LoginForm />);
    await fill(user);
    await screen.findByRole('heading', { name: 'Confirmar o código' });

    await user.type(screen.getByLabelText(/^Código/), '000000');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Código incorreto',
    );

    await user.clear(screen.getByLabelText(/^Código/));
    await user.type(screen.getByLabelText(/^Código/), 'abcd-efgh');
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));
    await waitFor(() => expect(replace).toHaveBeenCalledWith('/'));
    expect(call).toHaveBeenLastCalledWith('POST', 'auth/2fa/verify', {
      body: { code: 'abcd-efgh' },
    });
  });

  it('CA-02 clears the password from memory once the code step starts', async () => {
    call.mockResolvedValue({ status: 200, data: { next: 'verify' } });
    const user = userEvent.setup();
    const { container } = render(<LoginForm />);

    await fill(user);
    await screen.findByRole('heading', { name: 'Confirmar o código' });

    expect(container.querySelector('input[type="password"]')).toBeNull();
  });

  it('CA-02 opens the 2fa setup when the owner has to turn it on, and asks to sign in again at the end', async () => {
    call
      .mockResolvedValueOnce({ status: 200, data: { next: 'setup' } })
      .mockResolvedValueOnce({
        status: 200,
        data: { secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/x' },
      })
      .mockResolvedValueOnce({
        status: 200,
        data: { recovery_codes: ['aaaa-bbbb', 'cccc-dddd'] },
      });
    const user = userEvent.setup();
    render(<LoginForm />);
    await fill(user);

    expect(
      await screen.findByRole('heading', {
        name: 'Configure a verificação em duas etapas',
      }),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole('button', { name: 'Ligar a verificação' }),
    );
    expect(await screen.findByTestId('two-factor-secret')).toHaveTextContent(
      'JBSWY3DPEHPK3PXP',
    );
    await user.type(screen.getByLabelText(/^Código de 6 dígitos/), '123456');
    await user.click(screen.getByRole('button', { name: 'Confirmar e ligar' }));
    expect(await screen.findByText('aaaa-bbbb')).toBeInTheDocument();
    await user.click(
      screen.getByRole('button', { name: 'Guardei os códigos' }),
    );

    expect(
      await screen.findByText(/Entre de novo para continuar/),
    ).toBeInTheDocument();
    expect(screen.getByLabelText(/^E-mail/)).toBeInTheDocument();
  });

  it('CA-16 has no accessibility violations on the login, the code step and with an error', async () => {
    call.mockResolvedValueOnce({ status: 200, data: { next: 'verify' } });
    const user = userEvent.setup();
    const { container } = render(<LoginForm />);
    expect(await axe(container)).toHaveNoViolations();
    expect(
      screen.getByRole('link', { name: 'Esqueci minha senha' }),
    ).toHaveAttribute('href', '/esqueci-senha');

    await fill(user);
    await screen.findByRole('heading', { name: 'Confirmar o código' });
    expect(await axe(container)).toHaveNoViolations();
  });
});
