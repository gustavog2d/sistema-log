import { describe, expect, it } from 'vitest';
import {
  goalProgress,
  weekdayIndex,
  weeksOf,
  type FinanceDay,
} from './finance-types';

const days = (month: string, count: number): FinanceDay[] =>
  Array.from({ length: count }, (_, i) => ({
    date: `${month}-${String(i + 1).padStart(2, '0')}`,
    received_cents: 0,
    expected_cents: 0,
    appointments_count: 0,
    expenses_cents: 0,
  }));

describe('finance-types', () => {
  it('CA-13 computes the goal progress, which can pass 100%, and none without a goal', () => {
    expect(goalProgress(50000, 100000)).toBe(50);
    expect(goalProgress(150000, 100000)).toBe(150);
    expect(goalProgress(1000, null)).toBeNull();
    expect(goalProgress(1000, 0)).toBeNull();
  });

  it('CA-13 numbers the weekday with monday as the first', () => {
    expect(weekdayIndex('2026-11-02')).toBe(0);
    expect(weekdayIndex('2026-11-08')).toBe(6);
    expect(weekdayIndex('2026-11-01')).toBe(6);
  });

  it('CA-13 splits the month into weeks of seven, padding the edges', () => {
    const weeks = weeksOf(days('2026-11', 30));

    // 1º de novembro de 2026 é domingo: a primeira semana tem seis espaços vazios.
    expect(weeks).toHaveLength(6);
    expect(weeks.every((week) => week.length === 7)).toBe(true);
    expect(weeks[0]?.slice(0, 6).every((cell) => cell === null)).toBe(true);
    expect(weeks[0]?.[6]?.date).toBe('2026-11-01');
    expect(weeks[5]?.[0]?.date).toBe('2026-11-30');
    expect(weeksOf([])).toEqual([]);
    expect(weeksOf(days('2026-02', 28))).toHaveLength(5);
  });
});
