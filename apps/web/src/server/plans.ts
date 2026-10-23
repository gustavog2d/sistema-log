import type { PublicPlan } from '@/lib/plans';
import { hostAwareFetch } from './upstream';

const TTL_MS = 5 * 60 * 1000;
let cached: { at: number; plans: PublicPlan[] } | null = null;

/** Zera o cache (testes). */
export function resetPlansCache(): void {
  cached = null;
}

/**
 * Planos para as páginas do servidor (landing e cadastro). Guardados por 5 minutos e, se a API falhar,
 * o último resultado bom continua valendo: a página pública não cai junto com a API nem dispara uma chamada por visita.
 * Devolve `null` só quando nunca houve resposta boa.
 */
export async function loadPlans(
  now: number = Date.now(),
): Promise<PublicPlan[] | null> {
  if (cached && now - cached.at < TTL_MS) {
    return cached.plans;
  }

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

    if (response.ok) {
      const body = (await response.json()) as { data?: PublicPlan[] };

      if (Array.isArray(body.data)) {
        cached = { at: now, plans: body.data };

        return body.data;
      }
    }
  } catch {
    // Cai no último resultado bom, se houver.
  }

  return cached?.plans ?? null;
}
