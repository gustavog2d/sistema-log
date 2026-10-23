import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import type { Tone } from './badge';

const tones: Record<Exclude<Tone, 'neutral'>, string> = {
  success: 'border-success-fg bg-success-bg text-success-fg',
  warning: 'border-warning-fg bg-warning-bg text-warning-fg',
  danger: 'border-danger-fg bg-danger-bg text-danger-fg',
  info: 'border-info-fg bg-info-bg text-info-fg',
};

/** Aviso na página. Erro e alerta são anunciados na hora (`role="alert"`); os demais, com calma (`status`). */
export function Alert({
  tone = 'info',
  title,
  children,
  className,
}: {
  tone?: Exclude<Tone, 'neutral'>;
  title?: string;
  children?: ReactNode;
  className?: string;
}) {
  return (
    <div
      role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'}
      className={cn(
        'rounded-md border-l-4 p-4 text-sm',
        tones[tone],
        className,
      )}
    >
      {title ? <p className="font-semibold">{title}</p> : null}
      {children ? <div className={title ? 'mt-1' : ''}>{children}</div> : null}
    </div>
  );
}
