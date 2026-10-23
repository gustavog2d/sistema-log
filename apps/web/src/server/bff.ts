import { classifyHost, hostname, type HostKind } from './host';

/**
 * Ponte entre o navegador e a API (RN-07): o navegador fala só com `/bff/*` no próprio endereço; este código
 * repassa à API com o endereço do negócio e guarda o token em cookie HttpOnly, longe do JavaScript da página.
 */

export const SESSION_COOKIE = 'session';
export const CHALLENGE_COOKIE = 'challenge';

/** Caminhos da API que o navegador pode chamar por aqui. */
const ALLOWED = [
  /^plans$/,
  /^leads$/,
  /^signup(\/[a-z-]+)?$/,
  /^tenant\/current$/,
  /^auth\/.+$/,
  /^subscription(\/.*)?$/,
  /^users(\/.*)?$/,
  /^audit-logs$/,
  /^platform\/.+$/,
];

/** Caminhos que usam o token de desafio (login em duas etapas ou configuração do 2FA) quando não há sessão. */
const CHALLENGE_PATHS = [
  /^auth\/2fa\/(verify|setup|confirm)$/,
  /^platform\/auth\/2fa\/verify$/,
  /^platform\/2fa\/(setup|confirm)$/,
];

/** Respostas cujo `access_token` fica fora do corpo (a exceção é o acesso de suporte, entregue à tela da equipe). */
const KEEP_TOKEN = [/^platform\/tenants\/[0-9a-f-]{36}\/impersonate$/];

const LOGOUT = [/^auth\/logout(-all)?$/, /^platform\/auth\/logout$/];

const FORWARDED_RESPONSE_HEADERS = [
  'content-type',
  'retry-after',
  'idempotent-replayed',
  'www-authenticate',
];

export type BffDeps = {
  fetch: typeof fetch;
  apiUrl: string;
  secureCookies: boolean;
  baseDomain: string;
  /** Repassa o X-Forwarded-For do cliente (só atrás do nosso próprio balanceador); fora disso o cabeçalho é descartado. */
  trustForwardedFor?: boolean;
  maxBodyBytes?: number;
  timeoutMs?: number;
};

export function cookieValue(request: Request, name: string): string | null {
  const header = request.headers.get('cookie') ?? '';

  for (const part of header.split(';')) {
    const [key, ...rest] = part.trim().split('=');

    if (key === name) {
      try {
        return decodeURIComponent(rest.join('='));
      } catch {
        return null;
      }
    }
  }

  return null;
}

function serialize(
  name: string,
  value: string,
  maxAgeSeconds: number,
  secure: boolean,
): string {
  return [
    `${name}=${encodeURIComponent(value)}`,
    'Path=/',
    'HttpOnly',
    'SameSite=Lax',
    `Max-Age=${Number.isFinite(maxAgeSeconds) ? Math.max(0, Math.floor(maxAgeSeconds)) : 0}`,
    secure ? 'Secure' : null,
  ]
    .filter(Boolean)
    .join('; ');
}

function problem(status: number, title: string): Response {
  return new Response(JSON.stringify({ type: 'about:blank', title, status }), {
    status,
    headers: { 'content-type': 'application/problem+json' },
  });
}

/** Cada segmento do caminho: letras, números, hífen e sublinhado (inclui UUIDs). Nada de ponto, `%`, `?`, `#` ou `;`. */
const SEGMENT = /^[A-Za-z0-9_-]+$/;

/** Caminho da API pedido (sem o prefixo `/bff/`), ou `null` se for suspeito. */
export function apiPathFrom(pathname: string): string | null {
  const raw = pathname.replace(/^\/bff\/?/, '');

  // Barra, barra invertida, ponto e nulo codificados escondem um caminho diferente do que o filtro vê: recusa.
  if (
    /%2f|%5c|%2e|%00/i.test(raw) ||
    raw.includes('..') ||
    raw.includes('\\')
  ) {
    return null;
  }

  let decoded: string;

  try {
    decoded = decodeURIComponent(raw);
  } catch {
    return null;
  }

  // Decodificado uma vez, cada segmento precisa ser simples: um `%252e` vira `%2e` aqui e seria um ponto para o parser de URL.
  if (!decoded.split('/').every((segment) => SEGMENT.test(segment))) {
    return null;
  }

  return ALLOWED.some((pattern) => pattern.test(decoded)) ? decoded : null;
}

/** Quem pode chamar o quê: o site (domínio base) usa a plataforma; o endereço de assinante usa as rotas do negócio. */
const SITE_ONLY = [/^platform\//, /^signup(\/|$)/, /^leads$/];
const TENANT_ONLY = [
  /^auth\//,
  /^users(\/|$)/,
  /^audit-logs$/,
  /^subscription(\/|$)/,
  /^tenant\/current$/,
];

export function pathAllowedFor(kind: HostKind['kind'], path: string): boolean {
  if (kind === 'invalid') {
    return false;
  }

  if (kind === 'site') {
    return !TENANT_ONLY.some((pattern) => pattern.test(path));
  }

  return !SITE_ONLY.some((pattern) => pattern.test(path));
}

export async function handleBff(
  request: Request,
  deps: BffDeps,
): Promise<Response> {
  const url = new URL(request.url);
  const path = apiPathFrom(url.pathname);

  if (path === null) {
    return problem(404, 'Not Found');
  }

  const hostKind = classifyHost(
    request.headers.get('host'),
    deps.baseDomain,
  ).kind;

  // O endereço decide o que se pode chamar: o Host que chega aqui é o que a API vai usar para achar o negócio.
  if (!pathAllowedFor(hostKind, path)) {
    return problem(404, 'Not Found');
  }

  const method = request.method.toUpperCase();

  if (!['GET', 'POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    return problem(405, 'Method Not Allowed');
  }

  // Proteção contra CSRF: quem muda dados precisa declarar que é a nossa tela (um formulário de outro site não consegue).
  if (method !== 'GET' && request.headers.get('x-requested-with') !== 'fetch') {
    return problem(403, 'Forbidden');
  }

  const headers = new Headers({
    accept: 'application/json',
    host: hostname(request.headers.get('host')),
  });

  const contentType = request.headers.get('content-type');
  const idempotency = request.headers.get('idempotency-key');

  if (contentType) {
    headers.set('content-type', contentType);
  }

  if (idempotency) {
    headers.set('idempotency-key', idempotency);
  }

  const forwardedFor = request.headers.get('x-forwarded-for');

  // O cabeçalho vem do cliente: só vale atrás do nosso balanceador, senão qualquer um forja o IP e foge dos limites.
  if (forwardedFor && deps.trustForwardedFor) {
    headers.set('x-forwarded-for', forwardedFor);
  }

  const session = cookieValue(request, SESSION_COOKIE);
  const challenge = cookieValue(request, CHALLENGE_COOKIE);
  const usesChallenge = CHALLENGE_PATHS.some((pattern) => pattern.test(path));
  const bearer = usesChallenge ? (session ?? challenge) : session;

  if (bearer) {
    headers.set('authorization', `Bearer ${bearer}`);
  }

  const maxBytes = deps.maxBodyBytes ?? 262_144;
  let body: string | undefined;

  if (!['GET', 'DELETE'].includes(method)) {
    if (Number(request.headers.get('content-length') ?? 0) > maxBytes) {
      return problem(413, 'Payload Too Large');
    }

    if (
      contentType &&
      !contentType.toLowerCase().startsWith('application/json')
    ) {
      return problem(415, 'Unsupported Media Type');
    }

    body = await request.text();

    if (new TextEncoder().encode(body).length > maxBytes) {
      return problem(413, 'Payload Too Large');
    }
  }

  let upstream: Response;

  try {
    upstream = await deps.fetch(
      `${deps.apiUrl.replace(/\/$/, '')}/api/v1/${path}${url.search}`,
      {
        method,
        headers,
        body,
        redirect: 'manual',
        cache: 'no-store',
        signal: AbortSignal.timeout(deps.timeoutMs ?? 15_000),
      },
    );
  } catch {
    return problem(502, 'Bad Gateway');
  }

  const out = new Headers();

  for (const name of FORWARDED_RESPONSE_HEADERS) {
    const value = upstream.headers.get(name);

    if (value) {
      out.set(name, value);
    }
  }

  out.set('cache-control', 'no-store');
  out.set('x-content-type-options', 'nosniff');

  const text = [204, 205, 304].includes(upstream.status)
    ? null
    : await upstream.text();
  let payload = text;

  if (text && (upstream.headers.get('content-type') ?? '').includes('json')) {
    try {
      const data = JSON.parse(text) as Record<string, unknown>;
      const keep = KEEP_TOKEN.some((pattern) => pattern.test(path));

      if (upstream.ok && typeof data.access_token === 'string' && !keep) {
        const expires =
          typeof data.expires_at === 'string'
            ? (Date.parse(data.expires_at) - Date.now()) / 1000
            : 3600;
        out.append(
          'set-cookie',
          serialize(
            SESSION_COOKIE,
            data.access_token,
            expires,
            deps.secureCookies,
          ),
        );
        out.append(
          'set-cookie',
          serialize(CHALLENGE_COOKIE, '', 0, deps.secureCookies),
        );
        delete data.access_token;
        delete data.token_type;
        data.authenticated = true;
        payload = JSON.stringify(data);
      } else if (upstream.ok && typeof data.challenge_token === 'string') {
        const expires =
          typeof data.expires_at === 'string'
            ? (Date.parse(data.expires_at) - Date.now()) / 1000
            : 300;
        out.append(
          'set-cookie',
          serialize(
            CHALLENGE_COOKIE,
            data.challenge_token,
            expires,
            deps.secureCookies,
          ),
        );
        delete data.challenge_token;
        payload = JSON.stringify(data);
      }
    } catch {
      // Corpo que não é JSON válido segue como veio.
    }
  }

  if (LOGOUT.some((pattern) => pattern.test(path)) && upstream.status < 500) {
    out.append(
      'set-cookie',
      serialize(SESSION_COOKIE, '', 0, deps.secureCookies),
    );
    out.append(
      'set-cookie',
      serialize(CHALLENGE_COOKIE, '', 0, deps.secureCookies),
    );
  }

  return new Response(payload, { status: upstream.status, headers: out });
}
