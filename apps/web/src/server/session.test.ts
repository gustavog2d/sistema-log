import { describe, expect, it, vi } from 'vitest';
import { apiGet, loadPanelSession } from './session';

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
const ME = {
  user: { id: '1', name: 'Ana', email: 'a@x.com', role: 'owner' },
  tenant: { id: 't', name: 'Estúdio', slug: 'estudio' },
  can_write: true,
  two_factor_enabled: false,
  impersonating: false,
};

function context(
  respond: (path: string) => Response,
  token: string | null = 'id|secret',
) {
  const calls: Array<{ url: string; init: RequestInit }> = [];
  const fetchMock = vi.fn(
    async (url: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(url), init: init ?? {} });

      return respond(String(url));
    },
  ) as unknown as typeof fetch;

  return {
    calls,
    ctx: {
      host: 'estudio.localhost:3000',
      token,
      apiUrl: 'http://api:8000',
      fetch: fetchMock,
    },
  };
}

describe('server session', () => {
  it('CA-17 reads the session with the business host (no port) and the bearer from the cookie', async () => {
    const { ctx, calls } = context((url) =>
      url.endsWith('auth/me') ? json(ME) : json({ status: 'active' }),
    );

    const session = await loadPanelSession(ctx);

    expect(session).toMatchObject({
      kind: 'ok',
      me: { user: { name: 'Ana' } },
    });
    const headers = calls[0]!.init.headers as Record<string, string>;
    expect(headers.host).toBe('estudio.localhost');
    expect(headers.authorization).toBe('Bearer id|secret');
    expect(calls.map((c) => c.url).sort()).toEqual([
      'http://api:8000/api/v1/auth/me',
      'http://api:8000/api/v1/subscription',
    ]);
  });

  it('CA-17 has no session without a cookie and does not even call the API', async () => {
    const { ctx, calls } = context(() => json({}), null);

    expect(await loadPanelSession(ctx)).toEqual({ kind: 'unauthenticated' });
    expect(calls).toHaveLength(0);
  });

  it('CA-17 treats a 401 as no session', async () => {
    const { ctx } = context(() => json({ status: 401 }, 401));

    expect(await loadPanelSession(ctx)).toEqual({ kind: 'unauthenticated' });
  });

  it('CA-17 reports an error (not a logout) when the API fails', async () => {
    const { ctx } = context(() => json({}, 500));

    expect(await loadPanelSession(ctx)).toEqual({ kind: 'error' });
  });

  it('keeps the session when only the subscription call fails', async () => {
    const { ctx } = context((url) =>
      url.endsWith('auth/me') ? json(ME) : json({}, 500),
    );

    expect(await loadPanelSession(ctx)).toMatchObject({
      kind: 'ok',
      subscription: null,
    });
  });

  it('survives a network failure and a body that is not JSON', async () => {
    const down = {
      host: 'a.localhost',
      token: 'x',
      fetch: (async () => {
        throw new Error('down');
      }) as unknown as typeof fetch,
    };
    expect(await apiGet('auth/me', down)).toEqual({ status: 0, data: null });

    const html = {
      host: 'a.localhost',
      token: 'x',
      fetch: (async () =>
        new Response('<html>', { status: 502 })) as unknown as typeof fetch,
    };
    expect(await apiGet('auth/me', html)).toEqual({ status: 0, data: null });
  });
});
