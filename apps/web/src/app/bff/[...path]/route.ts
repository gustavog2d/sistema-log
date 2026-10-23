import { handleBff } from '@/server/bff';

export const dynamic = 'force-dynamic';

function apiUrl(): string {
  const configured = process.env.API_URL;

  // Em produção a API tem de ser declarada: um padrão silencioso para localhost esconderia um erro de configuração.
  if (!configured && process.env.NODE_ENV === 'production') {
    throw new Error('Defina API_URL com o endereço interno da API.');
  }

  return configured ?? 'http://localhost:8000';
}

/** Cookies `Secure` por padrão; só ficam sem isso em desenvolvimento e testes (ou com COOKIE_SECURE=false). */
function secureCookies(): boolean {
  if (process.env.COOKIE_SECURE) {
    return process.env.COOKIE_SECURE === 'true';
  }

  return !['development', 'test'].includes(process.env.NODE_ENV ?? '');
}

function handle(request: Request): Promise<Response> {
  return handleBff(request, {
    fetch,
    apiUrl: apiUrl(),
    secureCookies: secureCookies(),
    baseDomain: process.env.APP_BASE_DOMAIN || 'localhost',
    trustForwardedFor: process.env.TRUST_FORWARDED_FOR === 'true',
  });
}

export const GET = handle;
export const POST = handle;
export const PUT = handle;
export const PATCH = handle;
export const DELETE = handle;
