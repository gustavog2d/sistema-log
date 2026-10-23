'use client';

import { useEffect, useRef, type ReactNode } from 'react';
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
  const ref = useRef<HTMLDivElement>(null);
  const danger = tone === 'danger';

  // O erro leva o foco (e é anunciado): quem usa teclado ou leitor de tela não precisa procurá-lo.
  useEffect(() => {
    if (danger) ref.current?.focus();
  }, [danger]);

  return (
    <div
      ref={ref}
      tabIndex={danger ? -1 : undefined}
      role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'}
      className={cn(
        'rounded-md border-l-4 p-4 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus',
        tones[tone],
        className,
      )}
    >
      {title ? <p className="font-semibold">{title}</p> : null}
      {children ? <div className={title ? 'mt-1' : ''}>{children}</div> : null}
    </div>
  );
}
