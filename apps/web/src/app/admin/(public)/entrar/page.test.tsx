import { beforeEach, describe, expect, it, vi } from 'vitest';

const redirect = vi.fn((path: string) => {
  throw new Error(`redirect:${path}`);
});
const session = vi.fn();
vi.mock('next/navigation', () => ({
  redirect: (path: string) => redirect(path),
  useRouter: () => ({ replace: vi.fn(), refresh: vi.fn() }),
}));
vi.mock('@/server/admin-session', () => ({
  getAdminSession: () => session(),
}));

import Page from './page';

beforeEach(() => {
  redirect.mockClear();
  session.mockReset();
});

describe('admin login page', () => {
  it('CA-03 sends a signed-in person to /admin', async () => {
    session.mockResolvedValue({ kind: 'ok', me: {} });

    await expect(Page({ searchParams: Promise.resolve({}) })).rejects.toThrow(
      'redirect:/admin',
    );
  });

  it('CA-03 shows the form to a visitor', async () => {
    session.mockResolvedValue({ kind: 'unauthenticated' });

    const element = await Page({
      searchParams: Promise.resolve({ voltar: '/admin/leads' }),
    });

    expect(element.props).toMatchObject({
      variant: 'admin',
      returnTo: '/admin/leads',
    });
    expect(redirect).not.toHaveBeenCalled();
  });
});
