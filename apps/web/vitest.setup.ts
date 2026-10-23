import '@testing-library/jest-dom/vitest';
import * as axeMatchers from 'vitest-axe/matchers';
import { createElement, type ComponentProps } from 'react';
import { expect, vi } from 'vitest';

expect.extend(axeMatchers);

// O axe tenta medir cores num canvas; o jsdom não tem canvas. O contraste da paleta é conferido em ui-tokens.
HTMLCanvasElement.prototype.getContext = (() =>
  null) as typeof HTMLCanvasElement.prototype.getContext;

// `next/link` atualiza o estado ao pré-carregar e enche os testes de avisos de `act`: nos testes basta um link comum.
vi.mock('next/link', () => ({
  default: ({ href, ...rest }: ComponentProps<'a'>) =>
    createElement('a', { href: String(href), ...rest }),
}));
