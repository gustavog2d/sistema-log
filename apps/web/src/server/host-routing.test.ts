import { describe, expect, it } from 'vitest';
import { classifyHost, hostname } from './host';
import { decideRoute } from './routing';

describe('host', () => {
  it('strips the port and the case', () => {
    expect(hostname('Estudio.Localhost:3000')).toBe('estudio.localhost');
  });

  it.each([
    ['localhost:3000', { kind: 'site' }],
    ['www.localhost', { kind: 'site' }],
    ['estudio.localhost:3000', { kind: 'tenant', slug: 'estudio' }],
    ['meu-estudio.localhost', { kind: 'tenant', slug: 'meu-estudio' }],
    ['a.b.localhost', { kind: 'invalid' }],
    ['admin.localhost', { kind: 'invalid' }],
    ['api.localhost', { kind: 'invalid' }],
    ['app.localhost', { kind: 'invalid' }],
    ['-x-.localhost', { kind: 'invalid' }],
    ['abc.localhost', { kind: 'tenant', slug: 'abc' }],
    ['ab.localhost', { kind: 'invalid' }],
    ['a.localhost', { kind: 'invalid' }],
    ['outro.com', { kind: 'invalid' }],
    ['localhost.evil.com', { kind: 'invalid' }],
    ['', { kind: 'invalid' }],
  ])('CA-12 classifies %s', (host, expected) => {
    expect(classifyHost(host, 'localhost')).toEqual(expected);
  });
});

describe('routing', () => {
  const route = (host: string, path: string) =>
    decideRoute(host, path, 'localhost');

  it('CA-12 serves the site and the staff panel on the base domain', () => {
    expect(route('localhost:3000', '/')).toEqual({ action: 'next' });
    expect(route('localhost:3000', '/cadastro')).toEqual({ action: 'next' });
    expect(route('localhost:3000', '/admin/assinantes')).toEqual({
      action: 'next',
    });
  });

  it('CA-12 rewrites the subscriber addresses to the /t group', () => {
    expect(route('estudio.localhost:3000', '/')).toEqual({
      action: 'rewrite',
      pathname: '/t',
    });
    expect(route('estudio.localhost:3000', '/equipe')).toEqual({
      action: 'rewrite',
      pathname: '/t/equipe',
    });
  });

  it('CA-12 does not mix the two worlds', () => {
    expect(route('localhost', '/t')).toEqual({ action: 'notFound' });
    expect(route('localhost', '/t/equipe')).toEqual({ action: 'notFound' });
    expect(route('estudio.localhost', '/admin')).toEqual({
      action: 'notFound',
    });
    expect(route('estudio.localhost', '/t/equipe')).toEqual({
      action: 'notFound',
    });
  });

  it('CA-12 answers 404 for unknown addresses', () => {
    expect(route('outro.com', '/')).toEqual({ action: 'notFound' });
    expect(route('a.b.localhost', '/')).toEqual({ action: 'notFound' });
  });

  it('lets framework files and the bridge pass on any valid address', () => {
    expect(route('estudio.localhost', '/bff/auth/me')).toEqual({
      action: 'next',
    });
    expect(route('localhost', '/_next/static/x.js')).toEqual({
      action: 'next',
    });
  });
});
