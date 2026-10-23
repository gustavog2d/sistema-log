import { describe, expect, it, vi } from 'vitest';
import { handleAdopt } from './adopt';
import type { BffDeps } from './bff';

const TOKEN = `0194a7c0-0000-7000-8000-000000000000|${'a'.repeat(40)}`;

function deps(
  respond: () => Response,
): BffDeps & { calls: Array<{ url: string; init: RequestInit }> } {
  const calls: Array<{ url: string; init: RequestInit }> = [];

  return {
    calls,
    apiUrl: 'http://api:8000',
    secureCookies: false,
    baseDomain: 'localhost',
    fetch: vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(url), init: init ?? {} });
      return respond();
    }) as unknown as typeof fetch,
  };
}

const req = (
  body: unknown,
  init: {
    host?: string;
    headers?: Record<string, string>;
    method?: string;
  } = {},
) =>
  new Request('http://estudio.localhost:3000/bff/session/adopt', {
    method: init.method ?? 'POST',
    body: typeof body === 'string' ? body : JSON.stringify(body),
    headers: {
      host: init.host ?? 'estudio.localhost:3000',
      'x-requested-with': 'fetch',
      'content-type': 'application/json',
      ...init.headers,
    },
  });

const me = (impersonating: boolean) => () =>
  new Response(JSON.stringify({ impersonating }), { status: 200 });

describe('handleAdopt', () => {
  it('CA-18 confirms the token with the API and puts it in an HttpOnly cookie, never in the answer', async () => {
    const d = deps(me(true));

    const response = await handleAdopt(req({ token: TOKEN }), d);
    const body = await response.json();

    expect(body).toEqual({ authenticated: true, impersonating: true });
    expect(JSON.stringify(body)).not.toContain('aaaa');
    const cookie = response.headers
      .getSetCookie()
      .find((c) => c.startsWith('session='))!;
    expect(cookie).toContain('HttpOnly');
    expect(cookie).toContain('SameSite=Lax');
    expect(cookie).toContain(`Max-Age=${30 * 60}`);
    const headers = d.calls[0]!.init.headers as Record<string, string>;
    expect(headers.authorization).toBe(`Bearer ${TOKEN}`);
    expect(headers.host).toBe('estudio.localhost');
    expect(d.calls[0]!.url).toBe('http://api:8000/api/v1/auth/me');
  });

  it('CA-18 gives an ordinary token a longer cookie', async () => {
    const response = await handleAdopt(req({ token: TOKEN }), deps(me(false)));

    expect(
      response.headers.getSetCookie().find((c) => c.startsWith('session=')),
    ).toContain(`Max-Age=${12 * 60 * 60}`);
  });

  it('CA-18 only works on a business address', async () => {
    const d = deps(me(true));

    expect(
      (await handleAdopt(req({ token: TOKEN }, { host: 'localhost:3000' }), d))
        .status,
    ).toBe(404);
    expect(
      (await handleAdopt(req({ token: TOKEN }, { host: 'a.b.localhost' }), d))
        .status,
    ).toBe(404);
    expect(d.calls).toHaveLength(0);
  });

  it('CA-18 needs the CSRF header, POST and a well formed token', async () => {
    const d = deps(me(true));

    expect(
      (
        await handleAdopt(
          req({ token: TOKEN }, { headers: { 'x-requested-with': '' } }),
          d,
        )
      ).status,
    ).toBe(403);
    expect(
      (await handleAdopt(req({ token: TOKEN }, { method: 'PUT' }), d)).status,
    ).toBe(405);
    expect((await handleAdopt(req({ token: 'x' }), d)).status).toBe(422);
    expect((await handleAdopt(req({}), d)).status).toBe(422);
    expect((await handleAdopt(req('{quebrado'), d)).status).toBe(400);
    expect(d.calls).toHaveLength(0);
  });

  it('CA-18 refuses a token the API does not accept and sets no cookie', async () => {
    const response = await handleAdopt(
      req({ token: TOKEN }),
      deps(() => new Response('{}', { status: 401 })),
    );

    expect(response.status).toBe(401);
    expect(response.headers.getSetCookie()).toEqual([]);
  });

  it('answers 502 when the API is down', async () => {
    const d = {
      ...deps(me(true)),
      fetch: (async () => {
        throw new Error('down');
      }) as unknown as typeof fetch,
    };

    expect((await handleAdopt(req({ token: TOKEN }), d)).status).toBe(502);
  });
});
