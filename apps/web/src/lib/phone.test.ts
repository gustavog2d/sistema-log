import { describe, expect, it } from 'vitest';
import {
  COUNTRIES,
  DEFAULT_COUNTRY,
  displayPhone,
  formatNational,
  splitE164,
  toE164,
} from './phone';

const country = (code: string) => COUNTRIES.find((item) => item.code === code)!;

describe('phone', () => {
  it('CLI-001 masks the Brazilian number while typing (mobile and landline)', () => {
    expect(formatNational(DEFAULT_COUNTRY, '1')).toBe('(1');
    expect(formatNational(DEFAULT_COUNTRY, '119')).toBe('(11) 9');
    expect(formatNational(DEFAULT_COUNTRY, '1199999')).toBe('(11) 9999-9');
    expect(formatNational(DEFAULT_COUNTRY, '11999998888')).toBe(
      '(11) 99999-8888',
    );
    expect(formatNational(DEFAULT_COUNTRY, '1133334444')).toBe(
      '(11) 3333-4444',
    );
    expect(formatNational(DEFAULT_COUNTRY, '119999988889999')).toBe(
      '(11) 99999-8888',
    );
    expect(formatNational(DEFAULT_COUNTRY, '')).toBe('');
  });

  it('CLI-001 groups the digits of other countries', () => {
    expect(formatNational(country('PT'), '912345678')).toBe('912 345 678');
  });

  it('CLI-001 builds the E.164 with the country code and no trunk zero', () => {
    expect(toE164(DEFAULT_COUNTRY, '(11) 99999-8888')).toBe('+5511999998888');
    expect(toE164(country('PT'), '912 345 678')).toBe('+351912345678');
    expect(toE164(country('AR'), '011 5555 1234')).toBe('+541155551234');
  });

  it('CLI-001 splits an E.164 by the longest country code and falls back to Brazil', () => {
    expect(splitE164('+5511999998888')).toEqual({
      country: DEFAULT_COUNTRY,
      national: '11999998888',
    });
    expect(splitE164('+351912345678').country.code).toBe('PT');
    expect(splitE164('+14155550123').country.code).toBe('US');
    expect(splitE164('+999123456')).toEqual({
      country: DEFAULT_COUNTRY,
      national: '999123456',
    });
  });

  it('CLI-001 shows a phone for lists', () => {
    expect(displayPhone('+5511999998888')).toBe('+55 (11) 99999-8888');
    expect(displayPhone('+351912345678')).toBe('+351 912 345 678');
  });
});
