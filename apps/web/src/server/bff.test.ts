import { describe, expect, it, vi } from 'vitest';
import { apiPathFrom, handleBff, type BffDeps } from './bff';

function deps(
  respond: (url: string, init: RequestInit) => Response,
  secure = false,
): BffDeps & { calls: Array<{ url: string; init: RequestInit }> } {
  const calls: Array<{ url: string; init: RequestInit }> = [];

  return {
    calls,
    apiUrl: 'http://api:8000',
    secureCookies: secure,
    baseDomain: 'localhost',
    fetch: vi.fn(async (url: RequestInfo | URL, init?: RequestInit) => {
      calls.push({ url: String(url), init: init ?? {} });

      return respond(String(url), init ?? {});
    }) as unknown as typeof fetch,
  };
}

const json = (body: unknown, status = 200, extra: HeadersInit = {}) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json', ...extra },
  });

const req = (path: string, init: RequestInit & { cookie?: string } = {}) => {
  const { cookie, ...rest } = init;
  const host =
    (rest.headers as Record<string, string> | undefined)?.host ??
    'estudio.localhost:3000';

  return new Request(`http://${host}${path}`, {
    ...rest,
    headers: {
      host,
      ...(rest.body ? { 'content-type': 'application/json' } : {}),
      'x-requested-with': 'fetch',
      ...(cookie ? { cookie } : {}),
      ...(rest.headers as Record<string, string> | undefined),
    },
  });
};

describe('apiPathFrom', () => {
  it.each([
    ['/bff/plans', 'plans'],
    ['/bff/auth/me', 'auth/me'],
    ['/bff/subscription/invoices', 'subscription/invoices'],
    ['/bff/platform/tenants/abc', 'platform/tenants/abc'],
    ['/bff/signup/slug-availability', 'signup/slug-availability'],
  ])('CA-11 allows %s', (path, expected) => {
    expect(apiPathFrom(path)).toBe(expected);
  });

  it.each([
    '/bff/health',
    '/bff/../etc/passwd',
    '/bff/auth/%2e%2e/health',
    '/bff/auth%2fme',
    '/bff/auth\\me',
    '/bff//auth/me',
    '/bff/auth/me/',
    '/bff/billing/run',
    '/bff/',
    '/bff',
    '/bff/plans%00',
    '/bff/auth/%252e%252e/%252e%252e/up',
    '/bff/plans%3fx=1',
    '/bff/plans%253fx=1',
    '/bff/plans%23x',
    '/bff/auth/me;x',
    '/bff/auth/me.json',
  ])('CA-11 refuses %s', (path) => {
    expect(apiPathFrom(path)).toBeNull();
  });
});

describe('handleBff', () => {
  it('CA-09 forwards with the business host and the bearer from the cookie', async () => {
    const d = deps(() => json({ ok: true }));

    const response = await handleBff(
      req('/bff/auth/me?x=1', { cookie: 'session=abc%7C123' }),
      d,
    );

    expect(response.status).toBe(200);
    expect(d.calls[0]!.url).toBe('http://api:8000/api/v1/auth/me?x=1');
    const headers = new Headers(d.calls[0]!.init.headers);
    expect(headers.get('authorization')).toBe('Bearer abc|123');
    expect(headers.get('host')).toBe('estudio.localhost');
  });

  it('CA-09 sends no Authorization without a cookie', async () => {
    const d = deps(() => json({}));

    await handleBff(req('/bff/plans'), d);

    expect(
      new Headers(d.calls[0]!.init.headers).get('authorization'),
    ).toBeNull();
  });

  it('CA-09 does not use the challenge cookie on ordinary paths', async () => {
    const d = deps(() => json({}));

    await handleBff(req('/bff/auth/me', { cookie: 'challenge=desafio' }), d);

    expect(
      new Headers(d.calls[0]!.init.headers).get('authorization'),
    ).toBeNull();
  });

  it('CA-09 uses the challenge cookie to finish the two-step login', async () => {
    const d = deps(() => json({}));

    await handleBff(
      req('/bff/auth/2fa/verify', {
        method: 'POST',
        body: '{"code":"1"}',
        cookie: 'challenge=desafio',
      }),
      d,
    );

    expect(new Headers(d.calls[0]!.init.headers).get('authorization')).toBe(
      'Bearer desafio',
    );
  });

  it('CA-09 forwards the body, content type and idempotency key', async () => {
    const d = deps(() => json({}, 201));

    await handleBff(
      req('/bff/signup', {
        method: 'POST',
        body: '{"a":1}',
        headers: {
          host: 'localhost:3000',
          'content-type': 'application/json',
          'idempotency-key': 'k1',
        },
      }),
      d,
    );

    const headers = new Headers(d.calls[0]!.init.headers);
    expect(d.calls[0]!.init.body).toBe('{"a":1}');
    expect(headers.get('idempotency-key')).toBe('k1');
    expect(headers.get('content-type')).toBe('application/json');
  });

  it('CA-10 turns the access token into an HttpOnly cookie and removes it from the body', async () => {
    const expires = new Date(Date.now() + 3600_000).toISOString();
    const d = deps(() =>
      json({
        access_token: 'uuid|secret',
        token_type: 'Bearer',
        expires_at: expires,
        user: { name: 'Ana' },
      }),
    );

    const response = await handleBff(
      req('/bff/auth/login', { method: 'POST', body: '{}' }),
      d,
    );
    const body = await response.json();
    const cookies = response.headers.getSetCookie();

    expect(body).toEqual({
      expires_at: expires,
      user: { name: 'Ana' },
      authenticated: true,
    });
    expect(JSON.stringify(body)).not.toContain('secret');
    const session = cookies.find((c) => c.startsWith('session=uuid%7Csecret'))!;
    expect(session).toContain('HttpOnly');
    expect(session).toContain('SameSite=Lax');
    expect(session).toContain('Path=/');
    expect(session).not.toContain('Secure');
    expect(Number(/Max-Age=(\d+)/.exec(session)![1])).toBeGreaterThan(3500);
  });

  it('CA-10 marks the cookie Secure when configured', async () => {
    const d = deps(
      () =>
        json({
          access_token: 'x|y',
          expires_at: new Date(Date.now() + 60_000).toISOString(),
        }),
      true,
    );

    const response = await handleBff(
      req('/bff/auth/login', { method: 'POST', body: '{}' }),
      d,
    );

    expect(response.headers.getSetCookie()[0]).toContain('Secure');
  });

  it('CA-10 keeps the challenge token in its own HttpOnly cookie', async () => {
    const d = deps(() =>
      json({
        challenge_token: 'desafio',
        next: 'verify',
        expires_at: new Date(Date.now() + 300_000).toISOString(),
      }),
    );

    const response = await handleBff(
      req('/bff/auth/login', { method: 'POST', body: '{}' }),
      d,
    );
    const body = await response.json();

    expect(body.challenge_token).toBeUndefined();
    expect(body.next).toBe('verify');
    expect(
      response.headers
        .getSetCookie()
        .some(
          (c) => c.startsWith('challenge=desafio') && c.includes('HttpOnly'),
        ),
    ).toBe(true);
  });

  it('CA-10 does not set cookies when the login fails', async () => {
    const d = deps(() => json({ title: 'Unauthorized', status: 401 }, 401));

    const response = await handleBff(
      req('/bff/auth/login', { method: 'POST', body: '{}' }),
      d,
    );

    expect(response.status).toBe(401);
    expect(response.headers.getSetCookie()).toEqual([]);
  });

  it('CA-10 clears the cookies on logout', async () => {
    const d = deps(() => new Response(null, { status: 204 }));

    const response = await handleBff(
      req('/bff/auth/logout', { method: 'POST', cookie: 'session=a%7Cb' }),
      d,
    );

    expect(response.status).toBe(204);
    expect(
      response.headers.getSetCookie().every((c) => c.includes('Max-Age=0')),
    ).toBe(true);
    expect(
      response.headers
        .getSetCookie()
        .map((c) => c.split('=')[0])
        .sort(),
    ).toEqual(['challenge', 'session']);
  });

  it('keeps the support access token in the body for the staff screen only', async () => {
    const d = deps(() =>
      json({
        access_token: 'suporte|tok',
        token_type: 'Bearer',
        expires_at: '2030-01-01T00:00:00Z',
        url: 'http://x.localhost',
      }),
    );

    const response = await handleBff(
      req(
        '/bff/platform/tenants/0194a7c0-0000-7000-8000-000000000000/impersonate',
        {
          method: 'POST',
          body: '{"reason":"x"}',
          headers: { host: 'localhost:3000' },
        },
      ),
      d,
    );

    expect((await response.json()).access_token).toBe('suporte|tok');
    expect(response.headers.getSetCookie()).toEqual([]);
  });

  it('CA-11 refuses writes without the X-Requested-With header', async () => {
    const d = deps(() => json({}));
    const request = new Request(
      'http://estudio.localhost:3000/bff/auth/logout',
      {
        method: 'POST',
        headers: { host: 'estudio.localhost:3000', cookie: 'session=a' },
      },
    );

    const response = await handleBff(request, d);

    expect(response.status).toBe(403);
    expect(d.calls).toHaveLength(0);
  });

  it('CA-11 answers 404 for paths outside the list without calling the API', async () => {
    const d = deps(() => json({}));

    expect((await handleBff(req('/bff/health'), d)).status).toBe(404);
    expect(d.calls).toHaveLength(0);
  });

  it('answers 405 for other methods', async () => {
    const d = deps(() => json({}));

    expect(
      (await handleBff(req('/bff/plans', { method: 'OPTIONS' }), d)).status,
    ).toBe(405);
  });

  it('answers 502 when the API is down', async () => {
    const d = deps(() => {
      throw new Error('down');
    });

    const response = await handleBff(req('/bff/plans'), d);

    expect(response.status).toBe(502);
    expect(response.headers.get('content-type')).toContain('problem+json');
  });

  it('forwards the useful response headers and never caches', async () => {
    const d = deps(() =>
      json({ title: 'Too Many' }, 429, {
        'retry-after': '30',
        'idempotent-replayed': 'true',
        'x-internal': 'segredo',
      }),
    );

    const response = await handleBff(req('/bff/plans'), d);

    expect(response.headers.get('retry-after')).toBe('30');
    expect(response.headers.get('idempotent-replayed')).toBe('true');
    expect(response.headers.get('x-internal')).toBeNull();
    expect(response.headers.get('cache-control')).toBe('no-store');
  });

  it('passes 204 responses through without a body', async () => {
    const d = deps(() => new Response(null, { status: 204 }));

    const response = await handleBff(
      req('/bff/users/invitations/0194a7c0-0000-7000-8000-000000000000', {
        method: 'DELETE',
      }),
      d,
    );

    expect(response.status).toBe(204);
    expect(await response.text()).toBe('');
  });

  it('does not follow redirects of the API', async () => {
    const d = deps(
      () =>
        new Response(null, {
          status: 302,
          headers: { location: 'http://evil.com' },
        }),
    );

    const response = await handleBff(req('/bff/plans'), d);

    expect(response.headers.get('location')).toBeNull();
    expect((d.calls[0]!.init as RequestInit).redirect).toBe('manual');
  });
});

describe('handleBff hardening', () => {
  it('ALTO-1 never lets an encoded traversal reach the API', async () => {
    const d = deps(() => json({}));

    expect(
      (await handleBff(req('/bff/auth/%252e%252e/%252e%252e/up'), d)).status,
    ).toBe(404);
    expect(d.calls).toHaveLength(0);
  });

  it('only builds URLs under /api/v1 on the configured API', async () => {
    const d = deps(() => json({}));

    await handleBff(
      req('/bff/users/0194a7c0-0000-7000-8000-000000000000/deactivate', {
        method: 'POST',
        body: '{}',
        headers: { 'content-type': 'application/json' },
      }),
      d,
    );

    expect(new URL(d.calls[0]!.url).origin).toBe('http://api:8000');
    expect(new URL(d.calls[0]!.url).pathname.startsWith('/api/v1/')).toBe(true);
  });

  it('refuses an address that is not ours', async () => {
    const d = deps(() => json({}));

    expect(
      (
        await handleBff(
          req('/bff/plans', { headers: { host: 'a.b.localhost' } }),
          d,
        )
      ).status,
    ).toBe(404);
    expect(
      (await handleBff(req('/bff/plans', { headers: { host: 'evil.com' } }), d))
        .status,
    ).toBe(404);
    expect(
      (
        await handleBff(
          req('/bff/plans', { headers: { host: 'admin.localhost' } }),
          d,
        )
      ).status,
    ).toBe(404);
    expect(d.calls).toHaveLength(0);
  });

  it('keeps the platform routes on the site address and the business routes on the business address', async () => {
    const d = deps(() => json({}));

    expect(
      (
        await handleBff(
          req('/bff/platform/me', { headers: { host: 'estudio.localhost' } }),
          d,
        )
      ).status,
    ).toBe(404);
    expect(
      (
        await handleBff(
          req('/bff/auth/me', { headers: { host: 'localhost:3000' } }),
          d,
        )
      ).status,
    ).toBe(404);
    expect(
      (
        await handleBff(
          req('/bff/subscription', { headers: { host: 'localhost' } }),
          d,
        )
      ).status,
    ).toBe(404);
    expect(
      (
        await handleBff(
          req('/bff/platform/me', { headers: { host: 'localhost' } }),
          d,
        )
      ).status,
    ).toBe(200);
    expect(
      (
        await handleBff(
          req('/bff/auth/me', { headers: { host: 'estudio.localhost' } }),
          d,
        )
      ).status,
    ).toBe(200);
    expect(
      (
        await handleBff(
          req('/bff/plans', { headers: { host: 'estudio.localhost' } }),
          d,
        )
      ).status,
    ).toBe(200);
  });

  it('drops the X-Forwarded-For of the client unless it sits behind our own balancer', async () => {
    const d = deps(() => json({}));
    await handleBff(
      req('/bff/plans', { headers: { 'x-forwarded-for': '1.2.3.4' } }),
      d,
    );
    expect(
      new Headers(d.calls[0]!.init.headers).get('x-forwarded-for'),
    ).toBeNull();

    const trusted = { ...deps(() => json({})), trustForwardedFor: true };
    await handleBff(
      req('/bff/plans', { headers: { 'x-forwarded-for': '1.2.3.4' } }),
      trusted,
    );
    expect(
      new Headers(trusted.calls[0]!.init.headers).get('x-forwarded-for'),
    ).toBe('1.2.3.4');
  });

  it('limits the body size and only takes JSON', async () => {
    const d = { ...deps(() => json({})), maxBodyBytes: 10 };

    const big = await handleBff(
      req('/bff/leads', {
        method: 'POST',
        body: 'x'.repeat(50),
        headers: { 'content-type': 'application/json' },
      }),
      d,
    );
    const form = await handleBff(
      req('/bff/leads', {
        method: 'POST',
        body: 'a=1',
        headers: {
          'content-type': 'application/x-www-form-urlencoded',
          host: 'localhost',
        },
      }),
      { ...d, maxBodyBytes: 1000 },
    );

    expect(big.status).toBe(404); // o endereço do assinante não chama /leads
    const site = await handleBff(
      req('/bff/leads', {
        method: 'POST',
        body: 'x'.repeat(50),
        headers: { 'content-type': 'application/json', host: 'localhost' },
      }),
      d,
    );
    expect(site.status).toBe(413);
    expect(form.status).toBe(415);
  });

  it('gives the upstream call a timeout', async () => {
    const d = deps(() => json({}));

    await handleBff(req('/bff/plans'), d);

    expect((d.calls[0]!.init as RequestInit).signal).toBeInstanceOf(
      AbortSignal,
    );
  });

  it('survives a malformed cookie and an invalid expiry', async () => {
    const d = deps(() =>
      json({ access_token: 'a|b', expires_at: 'não é data' }),
    );

    const response = await handleBff(
      req('/bff/auth/login', {
        method: 'POST',
        body: '{}',
        cookie: 'session=%E0%A4%A',
      }),
      d,
    );

    expect(response.status).toBe(200);
    expect(response.headers.getSetCookie()[0]).toContain('Max-Age=');
    expect(response.headers.getSetCookie()[0]).not.toContain('NaN');
    expect(response.headers.get('x-content-type-options')).toBe('nosniff');
  });
});
