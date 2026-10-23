const RESERVED = [
  'www',
  'admin',
  'api',
  'app',
  'mail',
  'static',
  'assets',
  'status',
  'docs',
  'blog',
  'suporte',
  'help',
];

/** Mesma regra da API: 3 a 30 caracteres, letras minúsculas, números e hífen, sem hífen nas pontas. */
const PATTERN = /^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/;

export type SlugProblem = 'format' | 'reserved';

/** `null` quando o endereço é aceitável; senão o motivo (a API ainda confere se está livre). */
export function slugProblem(slug: string): SlugProblem | null {
  if (!PATTERN.test(slug) || slug.startsWith('xn--')) {
    return 'format';
  }

  return RESERVED.includes(slug) ? 'reserved' : null;
}

/** Sugere um endereço a partir do nome: sem acento, minúsculo, hífen no lugar dos espaços. */
export function slugify(name: string): string {
  return name
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 30)
    .replace(/-+$/g, '');
}
