import { describe, expect, it } from 'vitest';
import { safeReturnPath } from './safe-path';

describe('safeReturnPath', () => {
  it.each(['/', '/assinatura', '/equipe', '/conta'])(
    'CA-03 keeps %s',
    (path) => {
      expect(safeReturnPath(path)).toBe(path);
    },
  );

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
