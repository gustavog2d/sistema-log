import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { contrastPairs, contrastRatio, tokens } from './index';

const css = readFileSync(new URL('./tokens.css', import.meta.url), 'utf8');

describe('tokens', () => {
  it('CA-01 keeps tokens.css and the TypeScript object with the same colors', () => {
    const fromCss = Object.fromEntries(
      [...css.matchAll(/--color-([a-z0-9-]+):\s*(#[0-9a-f]{6});/g)].map((m) => [
        m[1],
        m[2],
      ]),
    );

    expect(fromCss).toEqual(tokens.color);
  });

  it('CA-01 keeps radius and font in the css theme too', () => {
    for (const [name, value] of Object.entries(tokens.radius)) {
      expect(css).toContain(`--radius-${name}: ${value};`);
    }
    expect(css.replace(/\s+/g, ' ')).toContain(
      `--font-sans: ${tokens.font.sans};`,
    );
  });

  it.each(contrastPairs)(
    'CA-02 $name has enough contrast',
    ({ fg, bg, min }) => {
      expect(
        contrastRatio(tokens.color[fg], tokens.color[bg]),
      ).toBeGreaterThanOrEqual(min);
    },
  );

  it('computes known contrast ratios (black on white is 21:1)', () => {
    expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 1);
    expect(contrastRatio('#ffffff', '#ffffff')).toBeCloseTo(1, 5);
  });
});
