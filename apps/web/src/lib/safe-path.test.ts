import { describe, expect, it } from 'vitest';
import { safeAdminReturnPath, safeReturnPath } from './safe-path';

describe('safeReturnPath', () => {
  it.each([
    '/',
    '/assinatura',
    '/clientes',
    '/servicos',
    '/profissionais',
    '/equipe',
    '/historico',
    '/conta',
  ])('CA-03 keeps %s', (path) => {
    expect(safeReturnPath(path)).toBe(path);
  });

  it.each([
    '//evil.com',
    'https://evil.com',
    '/\\evil.com',
    '/assinatura?x=1',
    '/assinatura/../x',
    '/outra',
    '/equipe\n',
    '',
  ])('CA-03 sends %j to the start page', (path) => {
    expect(safeReturnPath(path)).toBe('/');
  });

  it('CA-03 sends a missing value to the start page', () => {
    expect(safeReturnPath(undefined)).toBe('/');
    expect(safeReturnPath(null)).toBe('/');
  });
});

describe('safeAdminReturnPath', () => {
  const ID = '0194a7c0-0000-7000-8000-000000000001';

  it.each([
    '/admin',
    '/admin/assinantes',
    '/admin/planos',
    '/admin/cupons',
    '/admin/leads',
    `/admin/assinantes/${ID}`,
  ])('CA-03 keeps %s', (path) => {
    expect(safeAdminReturnPath(path)).toBe(path);
  });

  it.each([
    '//evil.com',
    'https://evil.com',
    '/',
    '/assinatura',
    '/admin/../x',
    '/admin/assinantes/abc',
    `/admin/assinantes/${ID}?x=1`,
    '/admin/leads\n',
    '/administrador',
  ])('CA-03 sends %j to /admin', (path) => {
    expect(safeAdminReturnPath(path)).toBe('/admin');
  });

  it('CA-03 sends a missing value to /admin', () => {
    expect(safeAdminReturnPath(undefined)).toBe('/admin');
    expect(safeAdminReturnPath(null)).toBe('/admin');
  });
});
