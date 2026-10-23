import http from 'node:http';
import type { AddressInfo } from 'node:net';
import { afterEach, describe, expect, it } from 'vitest';
import { hostAwareFetch } from './upstream';

let server: http.Server | undefined;

afterEach(
  () =>
    new Promise<void>((done) => (server ? server.close(() => done()) : done())),
);

function listen(handler: http.RequestListener): Promise<string> {
  server = http.createServer(handler);

  return new Promise((resolve) =>
    server!.listen(0, '127.0.0.1', () =>
      resolve(`http://127.0.0.1:${(server!.address() as AddressInfo).port}`),
    ),
  );
}

describe('hostAwareFetch', () => {
  it('sends the Host header that was asked for (the API finds the business by it)', async () => {
    let seen = '';
    const base = await listen((request, response) => {
      seen = String(request.headers.host);
      response.setHeader('content-type', 'application/json');
      response.end('{"ok":true}');
    });

    const response = await hostAwareFetch(`${base}/api/v1/x`, {
      headers: { host: 'estudio.localhost' },
    });

    expect(seen).toBe('estudio.localhost');
    expect(await response.json()).toEqual({ ok: true });
  });

  it('forwards method, headers and body, and returns status and headers', async () => {
    let received = '';
    let method = '';
    let auth = '';
    const base = await listen((request, response) => {
      method = request.method ?? '';
      auth = String(request.headers.authorization);
      request.on('data', (chunk) => (received += chunk));
      request.on('end', () => {
        response.statusCode = 201;
        response.setHeader('retry-after', '7');
        response.end('criado');
      });
    });

    const response = await hostAwareFetch(`${base}/x`, {
      method: 'POST',
      body: '{"a":1}',
      headers: {
        authorization: 'Bearer abc',
        'content-type': 'application/json',
      },
    });

    expect([method, auth, received]).toEqual(['POST', 'Bearer abc', '{"a":1}']);
    expect(response.status).toBe(201);
    expect(response.headers.get('retry-after')).toBe('7');
    expect(await response.text()).toBe('criado');
  });

  it('handles 204 without a body and keeps repeated headers', async () => {
    const base = await listen((_request, response) => {
      response.statusCode = 204;
      response.setHeader('set-cookie', ['a=1', 'b=2']);
      response.end();
    });

    const response = await hostAwareFetch(`${base}/x`);

    expect(response.status).toBe(204);
    expect(await response.text()).toBe('');
    expect(response.headers.getSetCookie()).toEqual(['a=1', 'b=2']);
  });

  it('never asks for a compressed response and refuses a body that is not text', async () => {
    let encoding = '';
    const base = await listen((request, response) => {
      encoding = String(request.headers['accept-encoding']);
      response.end('ok');
    });

    await hostAwareFetch(`${base}/x`, {
      headers: { 'accept-encoding': 'gzip' },
    });

    expect(encoding).toBe('identity');
    expect(() =>
      hostAwareFetch(`${base}/x`, {
        method: 'POST',
        body: new Uint8Array([1]),
      }),
    ).toThrow(TypeError);
  });

  it('stops a response above the size limit', async () => {
    const base = await listen((_request, response) => {
      response.write(Buffer.alloc(3 * 1024 * 1024));
      response.write(Buffer.alloc(3 * 1024 * 1024));
      response.end();
    });

    await expect(hostAwareFetch(`${base}/x`)).rejects.toBeTruthy();
  });

  it('rejects when the server is down and when the call is aborted', async () => {
    await expect(hostAwareFetch('http://127.0.0.1:9/x')).rejects.toBeTruthy();

    const base = await listen(() => {
      /* nunca responde */
    });
    await expect(
      hostAwareFetch(`${base}/x`, { signal: AbortSignal.timeout(50) }),
    ).rejects.toBeTruthy();
  });
});
