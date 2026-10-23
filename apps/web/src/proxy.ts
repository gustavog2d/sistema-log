import { NextResponse, type NextRequest } from 'next/server';
import { decideRoute } from '@/server/routing';

/** Roteia pelo endereço (site, painel da equipe e painel do assinante): ver `server/routing.ts`. */
export function proxy(request: NextRequest) {
  const decision = decideRoute(
    request.headers.get('host'),
    request.nextUrl.pathname,
    process.env.APP_BASE_DOMAIN || 'localhost',
  );

  if (decision.action === 'notFound') {
    return new NextResponse('Not Found', { status: 404 });
  }

  if (decision.action === 'rewrite') {
    const url = request.nextUrl.clone();
    url.pathname = decision.pathname;

    return NextResponse.rewrite(url);
  }

  return NextResponse.next();
}

export const config = { matcher: ['/((?!_next/static|_next/image).*)'] };
