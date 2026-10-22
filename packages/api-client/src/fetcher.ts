// Fetch usado pelo cliente gerado: resolve a URL base da API e normaliza a resposta.
const baseUrl = (): string =>
  (
    process.env.API_URL ??
    process.env.NEXT_PUBLIC_API_URL ??
    'http://localhost:8000'
  ).replace(/\/$/, '') + '/api/v1';

export const customFetch = async <T>(
  url: string,
  options: RequestInit,
): Promise<T> => {
  const response = await fetch(`${baseUrl()}${url}`, options);
  const body = [204, 205, 304].includes(response.status)
    ? null
    : await response.text();
  const data = body ? JSON.parse(body) : {};

  return { data, status: response.status, headers: response.headers } as T;
};
