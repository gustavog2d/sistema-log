import type { ButtonHTMLAttributes } from 'react';
import { cn } from '@/lib/cn';
import { Spinner } from './spinner';

export type ButtonVariant =
  'primary' | 'accent' | 'secondary' | 'ghost' | 'danger';

const variants: Record<ButtonVariant, string> = {
  primary: 'bg-brand-600 text-surface hover:bg-brand-700',
  accent: 'bg-accent-500 text-ink hover:bg-accent-400',
  secondary:
    'border border-control-border bg-surface text-ink hover:bg-slate-100',
  ghost: 'text-brand-700 hover:bg-brand-50',
  danger: 'bg-danger-fg text-surface hover:bg-slate-900',
};

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  loading?: boolean;
};

/** Botão com alvo de toque de 44 px, foco visível e estado de carregamento (fica desabilitado enquanto envia). */
export function Button({
  variant = 'primary',
  loading = false,
  disabled,
  className,
  children,
  type = 'button',
  ...rest
}: ButtonProps) {
  return (
    <button
      type={type}
      disabled={disabled || loading}
      aria-busy={loading || undefined}
      className={cn(
        'inline-flex min-h-11 min-w-11 items-center justify-center gap-2 rounded-md px-4 text-sm font-semibold transition-colors',
        'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus',
        'disabled:cursor-not-allowed disabled:opacity-60',
        variants[variant],
        className,
      )}
      {...rest}
    >
      {loading ? <Spinner className="size-4" /> : null}
      {children}
    </button>
  );
}
