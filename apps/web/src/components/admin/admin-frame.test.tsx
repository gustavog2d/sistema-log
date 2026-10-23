import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { adminMe } from './admin-fixtures';
import { AdminFrame } from './admin-frame';

const call = vi.fn();
const replace = vi.fn();
const refresh = vi.fn();
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => call(...args),
}));
vi.mock('next/navigation', () => ({
  useRouter: () => ({ replace, refresh, push: vi.fn() }),
  usePathname: () => '/admin/cupons',
}));

beforeEach(() => {
  call.mockReset();
  replace.mockReset();
  refresh.mockReset();
});

describe('AdminFrame', () => {
  it('CA-04 shows the menu, marks the current page and names the profile', () => {
    render(
      <AdminFrame me={adminMe('suporte')}>
        <p>conteúdo</p>
      </AdminFrame>,
    );

    for (const label of ['Resumo', 'Assinantes', 'Planos', 'Cupons', 'Leads']) {
      expect(screen.getByRole('link', { name: label })).toBeInTheDocument();
    }
    expect(screen.getByRole('link', { name: 'Cupons' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    expect(screen.getByText('Suporte')).toBeInTheDocument();
    expect(screen.getByText('Gustavo')).toBeInTheDocument();
  });

  it('CA-13 logs out revoking the token and goes to the admin login', async () => {
    call.mockResolvedValue({ status: 204, data: null });
    const user = userEvent.setup();
    render(
      <AdminFrame me={adminMe()}>
        <p>conteúdo</p>
      </AdminFrame>,
    );

    await user.click(screen.getByRole('button', { name: 'Sair' }));

    await waitFor(() => expect(replace).toHaveBeenCalledWith('/admin/entrar'));
    expect(call).toHaveBeenCalledWith('POST', 'platform/auth/logout');
  });

  it('CA-14 has no accessibility violations', async () => {
    const { container } = render(
      <AdminFrame me={adminMe()}>
        <h1>Título</h1>
      </AdminFrame>,
    );

    expect(await axe(container)).toHaveNoViolations();
  });
});
