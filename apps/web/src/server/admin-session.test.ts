import { describe, expect, it, vi } from 'vitest';
import { loadAdminSession } from './admin-session';

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
const ME = { id: 'u', name: 'Gustavo', email: 'g@x.com', role: 'super_admin' };

function context(respond: () => Response, token: string | null = 'id|secret') {
  const calls: Array<{ url: string; init: RequestInit }> = [];
  const fetchMock = vi.fn(
    async (url: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(url), init: init ?? {} });

      return respond();
    },
  ) as unknown as typeof fetch;

  return {
    calls,
    ctx: {
      host: 'localhost:3000',
      token,
      apiUrl: 'http://api:8000',
      fetch: fetchMock,
    },
  };
}

describe('admin session', () => {
  it('CA-15 reads the team profile with the base host (no port) and the bearer from the cookie', async () => {
    const { ctx, calls } = context(() => json(ME));

    expect(await loadAdminSession(ctx)).toEqual({ kind: 'ok', me: ME });
    expect(calls[0]!.url).toBe('http://api:8000/api/v1/platform/me');
    const headers = calls[0]!.init.headers as Record<string, string>;
    expect(headers.host).toBe('localhost');
    expect(headers.authorization).toBe('Bearer id|secret');
  });

  it('CA-15 has no session without a cookie and does not call the API', async () => {
    const { ctx, calls } = context(() => json(ME), null);

    expect(await loadAdminSession(ctx)).toEqual({ kind: 'unauthenticated' });
    expect(calls).toHaveLength(0);
  });

  it.each([401, 403])(
    'CA-15 treats %s as no session (expired or challenge token)',
    async (status) => {
      const { ctx } = context(() => json({}, status));

      expect(await loadAdminSession(ctx)).toEqual({ kind: 'unauthenticated' });
    },
  );

  it('CA-15 reports an error (not a logout) when the API is down', async () => {
    const { ctx } = context(() => json({}, 500));

    expect(await loadAdminSession(ctx)).toEqual({ kind: 'error' });
  });
});
