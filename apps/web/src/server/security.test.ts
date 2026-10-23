import { describe, expect, it, vi } from 'vitest';
import config, {
  contentSecurityPolicy,
  securityHeaders,
} from '../../next.config';
import { loadPlans, resetPlansCache } from './plans';
import * as upstream from './upstream';

describe('security headers', () => {
  const header = (name: string) =>
    securityHeaders.find((item) => item.key === name)?.value;

  it('ALTO-1 forbids framing, sniffing and leaking the referrer, and turns off sensitive browser features', () => {
    expect(header('X-Frame-Options')).toBe('DENY');
    expect(header('X-Content-Type-Options')).toBe('nosniff');
    expect(header('Referrer-Policy')).toBe('strict-origin-when-cross-origin');
    expect(header('Permissions-Policy')).toContain('camera=()');
  });

  it('ALTO-1 limits where content can come from, allowing only our own site and the captcha provider', () => {
    expect(contentSecurityPolicy).toContain("default-src 'self'");
    expect(contentSecurityPolicy).toContain("frame-ancestors 'none'");
    expect(contentSecurityPolicy).toContain("object-src 'none'");
    expect(contentSecurityPolicy).toContain("connect-src 'self'");
    expect(contentSecurityPolicy).toContain("form-action 'self'");
    expect(contentSecurityPolicy).toContain(
      'https://www.google.com/recaptcha/',
    );
    expect(contentSecurityPolicy).not.toMatch(/\*/);
  });

  it('applies the headers to every route and hides the framework name', async () => {
    const rules = await config.headers!();

    expect(rules[0]!.source).toBe('/:path*');
    expect(rules[0]!.headers).toEqual(securityHeaders);
    expect(config.poweredByHeader).toBe(false);
  });
});

describe('loadPlans cache', () => {
  const plans = [{ key: 'a', name: 'A' }];
  const respond = (ok: boolean) =>
    vi
      .spyOn(upstream, 'hostAwareFetch')
      .mockImplementation(async () =>
        ok
          ? new Response(JSON.stringify({ data: plans }), { status: 200 })
          : new Response('x', { status: 500 }),
      );

  it('MÉDIO-2 asks the API once per five minutes', async () => {
    resetPlansCache();
    const spy = respond(true);

    await loadPlans(1_000);
    await loadPlans(1_000 + 60_000);
    expect(spy).toHaveBeenCalledTimes(1);

    await loadPlans(1_000 + 6 * 60_000);
    expect(spy).toHaveBeenCalledTimes(2);
    spy.mockRestore();
  });

  it('MÉDIO-2 keeps showing the last good answer when the API fails, and says null only if there never was one', async () => {
    resetPlansCache();
    respond(false);
    expect(await loadPlans(1)).toBeNull();

    resetPlansCache();
    const good = respond(true);
    expect(await loadPlans(1)).toEqual(plans);
    good.mockRestore();
    respond(false);

    expect(await loadPlans(1 + 10 * 60_000)).toEqual(plans);
    vi.restoreAllMocks();
  });

  it('survives a network failure', async () => {
    resetPlansCache();
    vi.spyOn(upstream, 'hostAwareFetch').mockRejectedValue(new Error('down'));

    expect(await loadPlans(1)).toBeNull();
    vi.restoreAllMocks();
  });
});
