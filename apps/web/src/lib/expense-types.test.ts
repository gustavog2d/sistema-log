import { describe, expect, it } from 'vitest';
import { addMonths, formatDay, monthRange, monthTitle } from './expense-types';

describe('expense-types', () => {
  it('CA-11 moves across year ends', () => {
    expect(addMonths('2026-11', 1)).toBe('2026-12');
    expect(addMonths('2026-12', 1)).toBe('2027-01');
    expect(addMonths('2026-01', -1)).toBe('2025-12');
    expect(addMonths('2026-03', -15)).toBe('2024-12');
  });

  it('CA-11 gives the first and last day of the month, including leap years', () => {
    expect(monthRange('2026-11')).toEqual({
      from: '2026-11-01',
      to: '2026-11-30',
    });
    expect(monthRange('2026-02').to).toBe('2026-02-28');
    expect(monthRange('2028-02').to).toBe('2028-02-29');
    expect(monthRange('2026-12').to).toBe('2026-12-31');
  });

  it('CA-11 writes the month and the day in Portuguese', () => {
    expect(monthTitle('2026-11')).toBe('novembro de 2026');
    expect(formatDay('2026-11-05')).toBe('05/11/2026');
  });
});
