import { render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const redirect = vi.fn((path: string) => {
  throw new Error(`redirect:${path}`);
});
const originalPath = vi.fn<() => string | null>();
const session = vi.fn();
vi.mock('next/navigation', () => ({
  redirect: (path: string) => redirect(path),
  usePathname: () => '/admin',
  useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }),
}));
vi.mock('next/headers', () => ({
  headers: async () => ({ get: () => originalPath() }),
}));
vi.mock('@/server/admin-session', () => ({
  getAdminSession: () => session(),
}));

import AdminPanelLayout from './layout';

const ME = { id: 'u', name: 'Gustavo', email: 'g@x.com', role: 'super_admin' };

beforeEach(() => {
  redirect.mockClear();
  originalPath.mockReset();
  session.mockReset();
});

describe('admin panel layout', () => {
  it('CA-03 sends a visitor without session to the login, remembering the internal page', async () => {
    session.mockResolvedValue({ kind: 'unauthenticated' });
    originalPath.mockReturnValue('/admin/cupons');

    await expect(AdminPanelLayout({ children: null })).rejects.toThrow(
      'redirect:/admin/entrar?voltar=%2Fadmin%2Fcupons',
    );
  });

  it('CA-03 does not remember an external or unknown page', async () => {
    session.mockResolvedValue({ kind: 'unauthenticated' });
    originalPath.mockReturnValue('//evil.com');

    await expect(AdminPanelLayout({ children: null })).rejects.toThrow(
      'redirect:/admin/entrar',
    );
    expect(redirect).toHaveBeenCalledWith('/admin/entrar');
  });

  it('CA-15 keeps the session and warns when the API is down', async () => {
    session.mockResolvedValue({ kind: 'error' });

    render(await AdminPanelLayout({ children: <p>conteúdo</p> }));

    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.queryByText('conteúdo')).not.toBeInTheDocument();
    expect(redirect).not.toHaveBeenCalled();
  });

  it('CA-04 renders the page inside the frame for a signed-in profile', async () => {
    session.mockResolvedValue({ kind: 'ok', me: ME });

    render(await AdminPanelLayout({ children: <p>conteúdo</p> }));

    expect(screen.getByText('conteúdo')).toBeInTheDocument();
    expect(
      screen.getByRole('link', { name: 'Assinantes' }),
    ).toBeInTheDocument();
  });
});
