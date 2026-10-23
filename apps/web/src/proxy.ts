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

  // O caminho que a pessoa pediu fica à disposição das páginas do servidor (para voltar a ele depois do login).
  const forwarded = new Headers(request.headers);
  forwarded.set('x-original-path', request.nextUrl.pathname);

  if (decision.action === 'rewrite') {
    const url = request.nextUrl.clone();
    url.pathname = decision.pathname;

    return NextResponse.rewrite(url, { request: { headers: forwarded } });
  }

  return NextResponse.next({ request: { headers: forwarded } });
}

export const config = { matcher: ['/((?!_next/static|_next/image).*)'] };
