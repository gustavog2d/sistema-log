import { handleBff } from '@/server/bff';

export const dynamic = 'force-dynamic';

function handle(request: Request): Promise<Response> {
  return handleBff(request, {
    fetch,
    apiUrl: process.env.API_URL ?? 'http://localhost:8000',
    secureCookies:
      process.env.COOKIE_SECURE === 'true' ||
      process.env.NODE_ENV === 'production',
  });
}

export const GET = handle;
export const POST = handle;
export const PUT = handle;
export const PATCH = handle;
export const DELETE = handle;
