import { describe, expect, it } from 'vitest';
import {
  addDays,
  dayOf,
  mondayOf,
  timeOf,
  todayInBusiness,
  weekRange,
} from './appointment-types';

describe('appointment-types', () => {
  it('CA-14 adds days across month and year ends', () => {
    expect(addDays('2026-11-28', 5)).toBe('2026-12-03');
    expect(addDays('2026-12-30', 3)).toBe('2027-01-02');
    expect(addDays('2026-11-03', -7)).toBe('2026-10-27');
  });

  it('CA-14 finds the monday of the week (sunday belongs to the week that ends)', () => {
    expect(mondayOf('2026-11-03')).toBe('2026-11-02');
    expect(mondayOf('2026-11-02')).toBe('2026-11-02');
    expect(mondayOf('2026-11-08')).toBe('2026-11-02');
    expect(mondayOf('2026-11-09')).toBe('2026-11-09');
  });

  it('CA-14 builds the week period on the business clock', () => {
    expect(weekRange('2026-11-02')).toEqual({
      from: '2026-11-02T00:00:00-03:00',
      to: '2026-11-09T00:00:00-03:00',
    });
  });

  it('CA-14 reads day and time on the business clock, not UTC', () => {
    // 02:00Z de 04/11 ainda é 23:00 de 03/11 em Brasília.
    expect(dayOf('2026-11-04T02:00:00Z')).toBe('2026-11-03');
    expect(timeOf('2026-11-04T02:00:00Z')).toBe('23:00');
    expect(todayInBusiness(new Date('2026-11-04T02:00:00Z'))).toBe(
      '2026-11-03',
    );
  });
});
