import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { LoginForm } from '@/components/panel/login-form';

const call = vi.fn();
const replace = vi.fn();
const refresh = vi.fn();
// O espião do Vitest relança a rejeição numa cópia sem tratamento: a falha vai por uma função comum.
let failure: unknown = null;
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) =>
    failure ? Promise.reject(failure) : call(...args),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace, refresh, push: vi.fn() }),
}));

beforeEach(() => {
  failure = null;
  call.mockReset();
  replace.mockReset();
  refresh.mockReset();
});

async function fill(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText(/^E-mail/), 'gustavo@exemplo.com');
  await user.type(screen.getByLabelText(/^Senha/), 'uma-senha-forte-123');
  await user.click(screen.getByRole('button', { name: 'Entrar' }));
}

describe('LoginForm (admin)', () => {
  it('CA-01 uses the platform routes, has no password recovery and asks for the code', async () => {
    call.mockResolvedValue({ status: 200, data: { next: 'verify' } });
    const user = userEvent.setup();
    render(<LoginForm variant="admin" />);

    expect(
      screen.getByRole('heading', { name: 'Entrar na área da equipe' }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole('link', { name: /Esqueci/ }),
    ).not.toBeInTheDocument();
    await fill(user);

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'platform/auth/login', {
        body: { email: 'gustavo@exemplo.com', password: 'uma-senha-forte-123' },
      }),
    );
    expect(await screen.findByLabelText(/^Código/)).toBeInTheDocument();
    expect(screen.queryByLabelText(/^Senha/)).not.toBeInTheDocument();
  });

  it('CA-02 verifies the code and goes to /admin (or to a safe internal return page)', async () => {
    call.mockResolvedValue({ status: 200, data: { authenticated: true } });
    const user = userEvent.setup();
    render(
      <LoginForm variant="admin" startAt="code" returnTo="/admin/cupons" />,
    );

    await user.type(screen.getByLabelText(/^Código/), '123456');
    await user.click(screen.getByRole('button', { name: /Confirmar|Entrar/ }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/admin/cupons'));
    expect(call).toHaveBeenCalledWith('POST', 'platform/auth/2fa/verify', {
      body: { code: '123456' },
    });
  });

  it('CA-03 ignores an external return address', async () => {
    call.mockResolvedValue({ status: 200, data: { authenticated: true } });
    const user = userEvent.setup();
    render(<LoginForm variant="admin" returnTo="//evil.com" />);

    await fill(user);

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/admin'));
  });

  it('CA-01 shows how long the lock lasts', async () => {
    failure = new ApiError('x', 423, {}, undefined, 900);
    const user = userEvent.setup();
    render(<LoginForm variant="admin" />);

    await fill(user);

    expect(await screen.findByRole('alert')).toHaveTextContent('15 min');
  });

  it('CA-02 starts the two-factor setup when the account has none, with the platform routes', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { secret: 'ABCDEF', otpauth_uri: 'otpauth://totp/x' },
    });
    const user = userEvent.setup();
    render(<LoginForm variant="admin" startAt="setup" />);

    await user.click(
      screen.getByRole('button', { name: /Ligar a verificação/ }),
    );

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith('POST', 'platform/2fa/setup'),
    );
  });

  it('CA-14 has no accessibility violations', async () => {
    const { container } = render(<LoginForm variant="admin" />);

    expect(await axe(container)).toHaveNoViolations();
  });
});
