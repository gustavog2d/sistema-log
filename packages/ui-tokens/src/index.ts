// Tokens de design compartilhados entre o web e (na Fase 5) o mobile. A fonte dos valores é este objeto;
// `tokens.css` é o espelho para o Tailwind e um teste garante que os dois não divergem (FND-006).
export const tokens = {
  color: {
    'brand-50': '#eef0fb',
    'brand-100': '#dde1f7',
    'brand-200': '#bcc3ef',
    'brand-300': '#939ce3',
    'brand-400': '#6a74d4',
    'brand-500': '#4f58c6',
    'brand-600': '#3b3fb3',
    'brand-700': '#2f3390',
    'brand-800': '#262a73',
    'brand-900': '#1e2158',
    'accent-400': '#f8b94a',
    'accent-500': '#f5a524',
    'accent-600': '#d68a0c',
    'slate-50': '#f8fafc',
    'slate-100': '#f1f5f9',
    'slate-200': '#e2e8f0',
    'slate-300': '#cbd5e1',
    'slate-400': '#94a3b8',
    'slate-500': '#64748b',
    'slate-600': '#475569',
    'slate-700': '#334155',
    'slate-800': '#1e293b',
    'slate-900': '#0f172a',
    'success-bg': '#f0fdf4',
    'success-fg': '#166534',
    'warning-bg': '#fffbeb',
    'warning-fg': '#92400e',
    'danger-bg': '#fef2f2',
    'danger-fg': '#991b1b',
    'danger-600': '#dc2626',
    'info-bg': '#eff6ff',
    'info-fg': '#1e40af',
    surface: '#ffffff',
    canvas: '#f8fafc',
    ink: '#0f172a',
    muted: '#475569',
    'control-border': '#64748b',
    line: '#e2e8f0',
    focus: '#4f58c6',
  },
  radius: { sm: '0.375rem', md: '0.625rem', lg: '1rem' },
  font: {
    sans: "ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
  },
} as const;

export type ColorToken = keyof typeof tokens.color;

/** Pares de texto/fundo (e de componente/fundo) que a interface usa, com o contraste mínimo exigido (WCAG 2.2 AA). */
export const contrastPairs: ReadonlyArray<{
  name: string;
  fg: ColorToken;
  bg: ColorToken;
  min: number;
}> = [
  { name: 'texto no cartão', fg: 'ink', bg: 'surface', min: 4.5 },
  { name: 'texto no fundo', fg: 'ink', bg: 'canvas', min: 4.5 },
  { name: 'texto secundário no cartão', fg: 'muted', bg: 'surface', min: 4.5 },
  { name: 'texto secundário no fundo', fg: 'muted', bg: 'canvas', min: 4.5 },
  { name: 'dica e placeholder', fg: 'slate-500', bg: 'surface', min: 4.5 },
  { name: 'botão principal', fg: 'surface', bg: 'brand-600', min: 4.5 },
  { name: 'botão principal (hover)', fg: 'surface', bg: 'brand-700', min: 4.5 },
  { name: 'botão de destaque', fg: 'ink', bg: 'accent-500', min: 4.5 },
  { name: 'botão de destaque (hover)', fg: 'ink', bg: 'accent-400', min: 4.5 },
  { name: 'link', fg: 'brand-700', bg: 'surface', min: 4.5 },
  { name: 'link no fundo', fg: 'brand-700', bg: 'canvas', min: 4.5 },
  { name: 'selo de sucesso', fg: 'success-fg', bg: 'success-bg', min: 4.5 },
  { name: 'selo de alerta', fg: 'warning-fg', bg: 'warning-bg', min: 4.5 },
  { name: 'selo de erro', fg: 'danger-fg', bg: 'danger-bg', min: 4.5 },
  { name: 'selo de informação', fg: 'info-fg', bg: 'info-bg', min: 4.5 },
  { name: 'texto de erro no cartão', fg: 'danger-fg', bg: 'surface', min: 4.5 },
  { name: 'menu lateral', fg: 'slate-100', bg: 'slate-900', min: 4.5 },
  {
    name: 'menu lateral secundário',
    fg: 'slate-300',
    bg: 'slate-900',
    min: 4.5,
  },
  { name: 'borda de campo', fg: 'control-border', bg: 'surface', min: 3 },
  { name: 'anel de foco', fg: 'focus', bg: 'surface', min: 3 },
  { name: 'erro de campo (borda)', fg: 'danger-600', bg: 'surface', min: 3 },
];

function channel(value: number): number {
  const v = value / 255;
  return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
}

export function luminance(hex: string): number {
  const n = parseInt(hex.slice(1), 16);
  return (
    0.2126 * channel((n >> 16) & 255) +
    0.7152 * channel((n >> 8) & 255) +
    0.0722 * channel(n & 255)
  );
}

export function contrastRatio(fg: string, bg: string): number {
  const [a, b] = [luminance(fg), luminance(bg)].sort((x, y) => y - x);
  return (a + 0.05) / (b + 0.05);
}
