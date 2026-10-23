import { describe, expect, it } from 'vitest';
import {
  centsToReais,
  fromDateInput,
  queryString,
  reaisToCents,
  toDateInput,
} from './list-utils';

describe('list utils', () => {
  it('builds a query string with only the filled fields', () => {
    expect(
      queryString({
        q: ' tinta ',
        status: '',
        limit: 20,
        cursor: null,
        x: undefined,
      }),
    ).toBe('?q=tinta&limit=20');
    expect(queryString({})).toBe('');
  });

  it('converts reais to cents and back', () => {
    expect(reaisToCents('15')).toBe(1500);
    expect(reaisToCents('15,9')).toBe(1590);
    expect(reaisToCents('54.90')).toBe(5490);
    expect(reaisToCents('')).toBeNull();
    expect(reaisToCents('abc')).toBeNaN();
    expect(reaisToCents('1,234')).toBeNaN();
    expect(centsToReais(5490)).toBe('54,90');
    expect(centsToReais(null)).toBe('');
  });

  it('converts dates using the Brasília day', () => {
    expect(fromDateInput('2026-12-31', 'start')).toBe(
      '2026-12-31T00:00:00-03:00',
    );
    expect(fromDateInput('2026-12-31', 'end')).toBe(
      '2026-12-31T23:59:59-03:00',
    );
    expect(fromDateInput('', 'end')).toBeNull();
    expect(toDateInput('2026-12-31T02:59:59Z')).toBe('2026-12-30');
    expect(toDateInput(null)).toBe('');
  });
});
