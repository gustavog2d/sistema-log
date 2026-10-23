import { classifyHost, hostname } from './host';
import { SESSION_COOKIE, CHALLENGE_COOKIE } from './bff';
import type { BffDeps } from './bff';

const TOKEN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\|[A-Za-z0-9]{40}$/;

function problem(status: number): Response {
  return new Response(JSON.stringify({ type: 'about:blank', status }), {
    status,
    headers: {
      'content-type': 'application/problem+json',
      'cache-control': 'no-store',
    },
  });
}

function cookie(
  name: string,
  value: string,
  maxAge: number,
  secure: boolean,
): string {
  return [
    `${name}=${encodeURIComponent(value)}`,
    'Path=/',
    'HttpOnly',
    'SameSite=Lax',
    `Max-Age=${maxAge}`,
    secure ? 'Secure' : null,
  ]
    .filter(Boolean)
    .join('; ');
}

/**
 * Acesso de suporte (PLT-009): a tela de suporte recebe o token no fragmento da URL (que não chega ao servidor) e o entrega
 * aqui. Só vale no endereço de um negócio e só com token que a API confirma; vira cookie HttpOnly e some da resposta.
 */
export async function handleAdopt(
  request: Request,
  deps: BffDeps,
): Promise<Response> {
  if (request.method !== 'POST') {
    return problem(405);
  }

  if (request.headers.get('x-requested-with') !== 'fetch') {
    return problem(403);
  }

  const host = request.headers.get('host');

  if (classifyHost(host, deps.baseDomain).kind !== 'tenant') {
    return problem(404);
  }

  if (Number(request.headers.get('content-length') ?? 0) > 1024) {
    return problem(413);
  }

  let token: unknown;

  try {
    token = ((await request.json()) as { token?: unknown }).token;
  } catch {
    return problem(400);
  }

  if (typeof token !== 'string' || !TOKEN.test(token)) {
    return problem(422);
  }

  let me: Response;

  try {
    me = await deps.fetch(`${deps.apiUrl.replace(/\/$/, '')}/api/v1/auth/me`, {
      method: 'GET',
      headers: {
        accept: 'application/json',
        host: hostname(host),
        authorization: `Bearer ${token}`,
      },
      redirect: 'manual',
      signal: AbortSignal.timeout(deps.timeoutMs ?? 8000),
    });
  } catch {
    return problem(502);
  }

  if (me.status !== 200) {
    return problem(401);
  }

  let data: { impersonating?: boolean };

  try {
    data = (await me.json()) as { impersonating?: boolean };
  } catch {
    return problem(502);
  }

  // Acesso de suporte vale 30 minutos; o cookie não precisa durar mais que isso.
  const maxAge = data.impersonating ? 30 * 60 : 12 * 60 * 60;
  const headers = new Headers({
    'content-type': 'application/json',
    'cache-control': 'no-store',
  });
  headers.append(
    'set-cookie',
    cookie(SESSION_COOKIE, token, maxAge, deps.secureCookies),
  );
  headers.append(
    'set-cookie',
    cookie(CHALLENGE_COOKIE, '', 0, deps.secureCookies),
  );

  return new Response(
    JSON.stringify({
      authenticated: true,
      impersonating: data.impersonating === true,
    }),
    { status: 200, headers },
  );
}
