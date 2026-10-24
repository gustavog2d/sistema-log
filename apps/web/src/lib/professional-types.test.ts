import { describe, expect, it } from 'vitest';
import { fromLocalInput, toLocalInput } from './professional-types';

describe('local date and time', () => {
  it('AGD-002 shows an instant on the business clock and sends it back with the offset', () => {
    expect(toLocalInput('2026-12-24T03:00:00+00:00')).toBe('2026-12-24T00:00');
    expect(toLocalInput('2026-12-24T15:30:00Z')).toBe('2026-12-24T12:30');
    expect(toLocalInput('2026-12-24T02:59:00Z')).toBe('2026-12-23T23:59');
    expect(fromLocalInput('2026-12-24T00:00')).toBe(
      '2026-12-24T00:00:00-03:00',
    );
    expect(
      new Date(
        fromLocalInput(toLocalInput('2026-12-24T03:00:00Z')),
      ).toISOString(),
    ).toBe('2026-12-24T03:00:00.000Z');
  });
});
