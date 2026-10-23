import { NextRequest } from 'next/server';
import { describe, expect, it } from 'vitest';
import { proxy } from './proxy';

const request = (host: string, path: string) =>
  new NextRequest(`http://${host}${path}`, { headers: { host } });

describe('proxy', () => {
  it('CA-12 lets the site address through', () => {
    const response = proxy(request('localhost:3000', '/cadastro'));

    expect(response.status).toBe(200);
    expect(response.headers.get('x-middleware-rewrite')).toBeNull();
  });

  it('CA-12 rewrites a subscriber address to the /t group', () => {
    const response = proxy(request('estudio.localhost:3000', '/equipe'));

    expect(response.headers.get('x-middleware-rewrite')).toContain('/t/equipe');
  });

  it('CA-12 answers 404 on the crossing and on unknown addresses', () => {
    expect(proxy(request('estudio.localhost:3000', '/admin')).status).toBe(404);
    expect(proxy(request('localhost:3000', '/t/equipe')).status).toBe(404);
    expect(proxy(request('outro.com', '/')).status).toBe(404);
    expect(proxy(request('admin.localhost:3000', '/')).status).toBe(404);
  });

  it('does not rewrite the bridge to the API', () => {
    const response = proxy(request('estudio.localhost:3000', '/bff/auth/me'));

    expect(response.headers.get('x-middleware-rewrite')).toBeNull();
  });
});
