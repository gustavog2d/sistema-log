import { handleAdopt } from '@/server/adopt';
import { hostAwareFetch } from '@/server/upstream';

export const dynamic = 'force-dynamic';

export function POST(request: Request): Promise<Response> {
  return handleAdopt(request, {
    fetch: hostAwareFetch,
    apiUrl: process.env.API_URL ?? 'http://localhost:8000',
    secureCookies: process.env.COOKIE_SECURE
      ? process.env.COOKIE_SECURE === 'true'
      : !['development', 'test'].includes(process.env.NODE_ENV ?? ''),
    baseDomain: process.env.APP_BASE_DOMAIN || 'localhost',
  });
}

export const GET = POST;
export const PUT = POST;
export const PATCH = POST;
export const DELETE = POST;
