import { describe, expect, it } from 'vitest';
import { centsToReais, reaisToCents } from './money';

describe('money', () => {
  it('AGD-001 converts reais typed by a person to cents', () => {
    expect(reaisToCents('15')).toBe(1500);
    expect(reaisToCents('15,9')).toBe(1590);
    expect(reaisToCents('15.90')).toBe(1590);
    expect(reaisToCents(' 80,50 ')).toBe(8050);
    expect(reaisToCents('0')).toBe(0);
    expect(reaisToCents('1500')).toBe(150000);
  });

  it('AGD-001 treats empty as no value and refuses text and ambiguous numbers', () => {
    expect(reaisToCents('')).toBeNull();
    expect(reaisToCents('   ')).toBeNull();
    expect(reaisToCents('abc')).toBeNaN();
    expect(reaisToCents('1,234')).toBeNaN();
    expect(reaisToCents('-5')).toBeNaN();
    expect(reaisToCents('1.500')).toBeNaN();
    expect(reaisToCents('1.500,00')).toBeNaN();
  });

  it('AGD-001 shows cents as reais with a comma', () => {
    expect(centsToReais(5490)).toBe('54,90');
    expect(centsToReais(0)).toBe('0,00');
    expect(centsToReais(null)).toBe('');
  });
});
