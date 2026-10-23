import { t } from '@/i18n';

/** Erro de chamada à API, já traduzido para a tela: mensagem geral e mensagens por campo (422). */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly fieldErrors: Record<string, string> = {},
    readonly code?: string,
    readonly retryAfter?: number,
  ) {
    super(message);
  }

  get isNetwork(): boolean {
    return this.status === 0;
  }
}

type Problem = {
  title?: string;
  detail?: string;
  code?: string;
  errors?: Record<string, string[] | string>;
};

export type ApiResult<T> = { status: number; data: T };

/** Chama a API pela ponte `/bff` e devolve os dados, ou lança `ApiError`. */
export async function call<T = unknown>(
  method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
  path: string,
  options: {
    body?: unknown;
    idempotencyKey?: string;
    signal?: AbortSignal;
  } = {},
): Promise<ApiResult<T>> {
  const headers: Record<string, string> = {
    accept: 'application/json',
    'x-requested-with': 'fetch',
  };

  if (options.body !== undefined) {
    headers['content-type'] = 'application/json';
  }

  if (options.idempotencyKey) {
    headers['idempotency-key'] = options.idempotencyKey;
  }

  let response: Response;

  try {
    response = await fetch(`/bff/${path.replace(/^\//, '')}`, {
      method,
      headers,
      body:
        options.body === undefined ? undefined : JSON.stringify(options.body),
      credentials: 'same-origin',
      signal: options.signal,
    });
  } catch {
    throw new ApiError(t('common.networkError'), 0);
  }

  const text = [204, 205].includes(response.status)
    ? ''
    : await response.text();
  let data: unknown = null;

  try {
    data = text ? JSON.parse(text) : null;
  } catch {
    data = null;
  }

  if (response.ok) {
    return { status: response.status, data: data as T };
  }

  throw toApiError(response, data as Problem | null);
}

function toApiError(response: Response, problem: Problem | null): ApiError {
  const fieldErrors: Record<string, string> = {};

  for (const [field, messages] of Object.entries(problem?.errors ?? {})) {
    const first = Array.isArray(messages) ? messages[0] : messages;

    if (typeof first === 'string') {
      fieldErrors[field] = first;
    }
  }

  const retry = Number(response.headers.get('retry-after'));
  const message =
    response.status >= 500
      ? t('common.genericError')
      : (problem?.detail ??
        Object.values(fieldErrors)[0] ??
        problem?.title ??
        t('common.genericError'));

  return new ApiError(
    message,
    response.status,
    fieldErrors,
    problem?.code,
    Number.isFinite(retry) && retry > 0 ? retry : undefined,
  );
}
