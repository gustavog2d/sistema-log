import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { formatBRL, yearlySavings } from './format';
import { slugProblem, slugify } from './slug';
import { tenantUrl } from './tenant-url';
import { readOrigin } from './utm';
import { CaptchaUnavailable, captchaMode, getCaptchaToken } from './captcha';
import { priceOf, type PublicPlan } from './plans';

describe('format', () => {
  it('formats cents as reais', () => {
    expect(formatBRL(1500).replace(/\s/g, ' ')).toBe('R$ 15,00');
    expect(formatBRL(16200).replace(/\s/g, ' ')).toBe('R$ 162,00');
    expect(formatBRL(0).replace(/\s/g, ' ')).toBe('R$ 0,00');
  });

  it('computes the yearly savings and never goes negative', () => {
    expect(yearlySavings(1500, 16200)).toBe(1800);
    expect(yearlySavings(1500, 99999)).toBe(0);
  });
});

describe('slug', () => {
  it.each([
    ['Estúdio Tinta Viva', 'estudio-tinta-viva'],
    ['  Barbearia do Zé!  ', 'barbearia-do-ze'],
    ['A', 'a'],
    ['', ''],
    ['x'.repeat(60), 'x'.repeat(30)],
    ['Manicure & Cia.', 'manicure-cia'],
  ])('slugifies %j as %j', (input, expected) => {
    expect(slugify(input)).toBe(expected);
  });

  it.each([
    ['estudio', null],
    ['ab', 'format'],
    ['-estudio', 'format'],
    ['estudio-', 'format'],
    ['Estudio', 'format'],
    ['estu dio', 'format'],
    ['xn--abc', 'format'],
    ['admin', 'reserved'],
    ['suporte', 'reserved'],
    ['a'.repeat(31), 'format'],
    ['a'.repeat(30), null],
  ])('checks %j', (slug, expected) => {
    expect(slugProblem(slug)).toBe(expected);
  });
});

describe('tenantUrl', () => {
  it('puts the business as a subdomain of the current address, keeping protocol and port', () => {
    expect(
      tenantUrl('estudio', { protocol: 'http:', host: 'localhost:3000' }),
    ).toBe('http://estudio.localhost:3000/');
    expect(
      tenantUrl(
        'estudio',
        { protocol: 'https:', host: 'www.exemplo.com' },
        '/entrar',
      ),
    ).toBe('https://estudio.exemplo.com/entrar');
  });
});

describe('utm', () => {
  beforeEach(() => {
    sessionStorage.clear();
    window.history.pushState(
      {},
      '',
      '/?utm_source=instagram&utm_medium=social&utm_campaign=lanc&x=1',
    );
  });

  afterEach(() => vi.restoreAllMocks());

  it('reads the origin from the page and keeps the first one for the session', () => {
    const first = readOrigin();
    window.history.pushState({}, '', '/outra?utm_source=google');
    const second = readOrigin();

    expect(first).toMatchObject({
      utm_source: 'instagram',
      utm_medium: 'social',
      utm_campaign: 'lanc',
      landing_path: '/',
    });
    expect(second).toEqual(first);
    expect(first).not.toHaveProperty('x');
  });

  it('cuts long values and drops referrers that are not http(s)', () => {
    window.history.pushState({}, '', `/?utm_source=${'a'.repeat(300)}`);
    vi.spyOn(document, 'referrer', 'get').mockReturnValue(
      'javascript:alert(1)',
    );

    const origin = readOrigin();

    expect(origin.utm_source).toHaveLength(100);
    expect(origin.referrer).toBeUndefined();
  });

  it('keeps an http(s) referrer', () => {
    vi.spyOn(document, 'referrer', 'get').mockReturnValue(
      'https://instagram.com/x',
    );

    expect(readOrigin().referrer).toBe('https://instagram.com/x');
  });

  it('survives a broken stored value', () => {
    sessionStorage.setItem('first-touch', '{quebrado');

    expect(readOrigin().utm_source).toBe('instagram');
  });
});

describe('captcha', () => {
  afterEach(() => vi.unstubAllEnvs());

  it('uses the test token only in fake mode', async () => {
    vi.stubEnv('NEXT_PUBLIC_CAPTCHA_MODE', 'fake');

    expect(captchaMode()).toBe('fake');
    expect(await getCaptchaToken('lead')).toBe('captcha_ok');
  });

  it('defaults to the real captcha and refuses to run without a site key', async () => {
    vi.stubEnv('NEXT_PUBLIC_CAPTCHA_MODE', '');
    vi.stubEnv('NEXT_PUBLIC_RECAPTCHA_SITE_KEY', '');

    expect(captchaMode()).toBe('recaptcha');
    await expect(getCaptchaToken('lead')).rejects.toBeInstanceOf(
      CaptchaUnavailable,
    );
  });

  it('asks Google for a token with the action when configured', async () => {
    vi.stubEnv('NEXT_PUBLIC_CAPTCHA_MODE', '');
    vi.stubEnv('NEXT_PUBLIC_RECAPTCHA_SITE_KEY', 'chave-publica');
    window.grecaptcha = {
      ready: (callback) => callback(),
      execute: vi.fn(async () => 'token-do-google'),
    };

    expect(await getCaptchaToken('lead')).toBe('token-do-google');
    expect(window.grecaptcha.execute).toHaveBeenCalledWith('chave-publica', {
      action: 'lead',
    });
    delete window.grecaptcha;
  });
});

describe('plans', () => {
  const plan = {
    monthly_price_cents: 1500,
    yearly_price_cents: 16200,
  } as PublicPlan;

  it('picks the price of the cycle', () => {
    expect(priceOf(plan, 'monthly')).toBe(1500);
    expect(priceOf(plan, 'yearly')).toBe(16200);
  });
});
