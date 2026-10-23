import { afterEach, describe, expect, it, vi } from 'vitest';
import { ApiError, call } from './api';

afterEach(() => vi.unstubAllGlobals());

function stub(response: Response | Error) {
  const fn = vi.fn(async () => {
    if (response instanceof Error) {
      throw response;
    }

    return response;
  });
  vi.stubGlobal('fetch', fn);

  return fn;
}

describe('call', () => {
  it('sends JSON through the bridge with the CSRF header', async () => {
    const fn = stub(new Response('{"ok":true}', { status: 200 }));

    const result = await call('POST', '/auth/login', {
      body: { a: 1 },
      idempotencyKey: 'k',
    });

    expect(result).toEqual({ status: 200, data: { ok: true } });
    const [url, init] = fn.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe('/bff/auth/login');
    expect(init.headers).toMatchObject({
      'x-requested-with': 'fetch',
      'content-type': 'application/json',
      'idempotency-key': 'k',
    });
    expect(init.credentials).toBe('same-origin');
    expect(init.body).toBe('{"a":1}');
  });

  it('returns null data for 204', async () => {
    stub(new Response(null, { status: 204 }));

    expect(await call('POST', 'auth/logout')).toEqual({
      status: 204,
      data: null,
    });
  });

  it('CA-13 maps validation errors to fields', async () => {
    stub(
      new Response(
        JSON.stringify({
          title: 'Unprocessable',
          detail: 'Dados inválidos.',
          errors: { email: ['E-mail inválido.', 'outra'], senha: 'Curta.' },
        }),
        { status: 422 },
      ),
    );

    const error = await call('POST', 'signup').catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).status).toBe(422);
    expect((error as ApiError).fieldErrors).toEqual({
      email: 'E-mail inválido.',
      senha: 'Curta.',
    });
    expect((error as ApiError).message).toBe('Dados inválidos.');
  });

  it('keeps the code and the retry-after of a problem', async () => {
    stub(
      new Response(
        JSON.stringify({
          title: 'Conflict',
          detail: 'Limite.',
          code: 'plan_limit_reached',
        }),
        { status: 409, headers: { 'retry-after': '12' } },
      ),
    );

    const error = (await call('POST', 'users/invitations').catch(
      (e: unknown) => e,
    )) as ApiError;

    expect(error.code).toBe('plan_limit_reached');
    expect(error.retryAfter).toBe(12);
  });

  it('CA-13 shows a generic message for server errors, hiding the detail', async () => {
    stub(
      new Response(JSON.stringify({ detail: 'SQLSTATE[...] vazou' }), {
        status: 500,
      }),
    );

    const error = (await call('GET', 'plans').catch(
      (e: unknown) => e,
    )) as ApiError;

    expect(error.message).not.toContain('SQLSTATE');
    expect(error.status).toBe(500);
  });

  it('CA-13 turns a network failure into an error with status 0', async () => {
    stub(new TypeError('Failed to fetch'));

    const error = (await call('GET', 'plans').catch(
      (e: unknown) => e,
    )) as ApiError;

    expect(error.isNetwork).toBe(true);
    expect(error.message).toMatch(/servidor/);
  });

  it('survives an error body that is not JSON', async () => {
    stub(new Response('<html>', { status: 502 }));

    const error = (await call('GET', 'plans').catch(
      (e: unknown) => e,
    )) as ApiError;

    expect(error.status).toBe(502);
    expect(error.fieldErrors).toEqual({});
  });
});
