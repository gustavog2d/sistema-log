import type { PublicPlan } from '@/lib/plans';
import { hostAwareFetch } from './upstream';

/** Planos para as páginas do servidor (landing e cadastro). Devolve `null` se a API estiver fora. */
export async function loadPlans(): Promise<PublicPlan[] | null> {
  const base = (process.env.API_URL ?? 'http://localhost:8000').replace(
    /\/$/,
    '',
  );

  try {
    const response = await hostAwareFetch(`${base}/api/v1/plans`, {
      headers: {
        accept: 'application/json',
        host: process.env.APP_BASE_DOMAIN || 'localhost',
      },
      signal: AbortSignal.timeout(5000),
    });

    if (!response.ok) {
      return null;
    }

    const body = (await response.json()) as { data?: PublicPlan[] };

    return Array.isArray(body.data) ? body.data : null;
  } catch {
    return null;
  }
}
