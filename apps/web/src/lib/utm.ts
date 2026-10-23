const KEYS = [
  'utm_source',
  'utm_medium',
  'utm_campaign',
  'utm_term',
  'utm_content',
] as const;
const STORAGE_KEY = 'first-touch';

export type Origin = Partial<Record<(typeof KEYS)[number], string>> & {
  referrer?: string;
  landing_path?: string;
};

/** Só as chaves de origem, só texto: o que está guardado no navegador não pode trazer outros campos para o envio. */
function clean(raw: Record<string, unknown>): Origin {
  const origin: Origin = {};

  for (const key of [...KEYS, 'referrer', 'landing_path'] as const) {
    const value = raw[key];

    if (typeof value === 'string' && value) {
      origin[key] = value.slice(0, 255);
    }
  }

  return origin;
}

function safeStorage(): Storage | null {
  try {
    return window.sessionStorage;
  } catch {
    return null;
  }
}

/**
 * Origem da primeira visita da sessão (UTM, referrer e página de entrada): lida da página na primeira vez
 * e guardada na sessão do navegador (só a origem; nunca dados da pessoa).
 */
export function readOrigin(): Origin {
  const storage = safeStorage();
  const saved = storage?.getItem(STORAGE_KEY);

  if (saved) {
    try {
      return clean(JSON.parse(saved) as Record<string, unknown>);
    } catch {
      // Valor estragado: lê de novo da página.
    }
  }

  const params = new URLSearchParams(window.location.search);
  const origin: Origin = {};

  for (const key of KEYS) {
    const value = params.get(key)?.trim().slice(0, 100);

    if (value) {
      origin[key] = value;
    }
  }

  const referrer = document.referrer.trim().slice(0, 255);

  // Só endereços http(s): é o que a API aceita (e o que a equipe pode abrir com segurança).
  if (/^https?:\/\//i.test(referrer)) {
    origin.referrer = referrer;
  }

  origin.landing_path = window.location.pathname.slice(0, 255);
  storage?.setItem(STORAGE_KEY, JSON.stringify(origin));

  return origin;
}
