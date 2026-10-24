import { describe, expect, it } from 'vitest';
import { formatDuration } from './service-types';

describe('formatDuration', () => {
  it('AGD-001 shows minutes, hours and both', () => {
    expect(formatDuration(45)).toBe('45 min');
    expect(formatDuration(60)).toBe('1 h');
    expect(formatDuration(90)).toBe('1 h 30 min');
    expect(formatDuration(720)).toBe('12 h');
    expect(formatDuration(5)).toBe('5 min');
  });
});
