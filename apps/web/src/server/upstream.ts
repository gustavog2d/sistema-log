import http from 'node:http';
import https from 'node:https';

const MAX_RESPONSE_BYTES = 5 * 1024 * 1024;

/**
 * `fetch` que respeita o cabeçalho `Host` pedido. O `fetch` do Node ignora esse cabeçalho, e a API descobre o negócio
 * (ou o domínio da plataforma) justamente pelo Host: sem isso toda chamada chegaria como `api:8000`.
 */
export function hostAwareFetch(
  input: RequestInfo | URL,
  init: RequestInit = {},
): Promise<Response> {
  const url = new URL(String(input));
  const headers = new Headers(init.headers);
  const transport = url.protocol === 'https:' ? https : http;

  return new Promise((resolve, reject) => {
    const request = transport.request(
      url,
      {
        method: init.method ?? 'GET',
        headers: Object.fromEntries(headers.entries()),
        signal: init.signal ?? undefined,
      },
      (response) => {
        const chunks: Buffer[] = [];
        let size = 0;

        response.on('data', (chunk: Buffer) => {
          size += chunk.length;

          if (size > MAX_RESPONSE_BYTES) {
            request.destroy(new Error('Resposta grande demais.'));

            return;
          }

          chunks.push(chunk);
        });
        response.on('end', () => {
          const out = new Headers();

          for (const [name, value] of Object.entries(response.headers)) {
            if (Array.isArray(value)) {
              value.forEach((item) => out.append(name, item));
            } else if (value !== undefined) {
              out.set(name, value);
            }
          }

          const status = response.statusCode ?? 502;
          const body = [204, 205, 304].includes(status)
            ? null
            : Buffer.concat(chunks);

          resolve(new Response(body, { status, headers: out }));
        });
        response.on('error', reject);
      },
    );

    request.on('error', reject);

    if (typeof init.body === 'string') {
      request.write(init.body);
    }

    request.end();
  });
}
