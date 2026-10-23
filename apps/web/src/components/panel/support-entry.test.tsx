import { render, screen, waitFor } from '@testing-library/react';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import { SupportEntry } from './support-entry';

const call = vi.fn();
// O espião do Vitest relança a rejeição numa cópia da promessa (erro sem tratamento): a recusa usa uma função comum.
let refuse: (() => Promise<never>) | null = null;
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) => (refuse ? refuse() : call(...args)),
}));

const TOKEN = `0194a7c0-0000-7000-8000-000000000000|${'a'.repeat(40)}`;

beforeEach(() => {
  refuse = null;
  call.mockReset();
});

describe('SupportEntry', () => {
  it('CA-14 hands the token from the fragment to the bridge, clears the address bar first and opens the panel', async () => {
    window.history.pushState(
      {},
      '',
      `/suporte#token=${encodeURIComponent(TOKEN)}`,
    );
    call.mockImplementation(async () => {
      expect(window.location.hash).toBe('');

      return { status: 200, data: { authenticated: true } };
    });
    const go = vi.fn();

    render(<SupportEntry go={go} />);

    await waitFor(() => expect(go).toHaveBeenCalledWith('/'));
    expect(call).toHaveBeenCalledWith('POST', 'session/adopt', {
      body: { token: TOKEN },
    });
    expect(window.location.href).not.toContain('token');
  });

  it('CA-14 shows a message and does not call the bridge without a token', async () => {
    window.history.pushState({}, '', '/suporte');
    const go = vi.fn();

    render(<SupportEntry go={go} />);

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'Acesso incompleto',
    );
    expect(call).not.toHaveBeenCalled();
    expect(go).not.toHaveBeenCalled();
  });

  it('CA-14 shows one message when the bridge refuses the token', async () => {
    window.history.pushState(
      {},
      '',
      `/suporte#token=${encodeURIComponent(TOKEN)}`,
    );
    refuse = () => Promise.reject(new ApiError('x', 401));
    const go = vi.fn();

    render(<SupportEntry go={go} />);

    expect(await screen.findByRole('alert')).toHaveTextContent(
      'inválido ou já venceu',
    );
    expect(go).not.toHaveBeenCalled();
  });

  it('CA-16 has no accessibility violations while working', async () => {
    window.history.pushState(
      {},
      '',
      `/suporte#token=${encodeURIComponent(TOKEN)}`,
    );
    let finish!: (value: unknown) => void;
    call.mockImplementation(() => new Promise((done) => (finish = done)));
    const { container } = render(<SupportEntry go={() => {}} />);

    expect(await axe(container)).toHaveNoViolations();
    finish({ status: 200, data: {} });
  });
});
