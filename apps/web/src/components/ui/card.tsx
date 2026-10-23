import type { HTMLAttributes, ReactNode } from 'react';
import { cn } from '@/lib/cn';

export function Card({ className, ...rest }: HTMLAttributes<HTMLDivElement>) {
  return (
    <div
      className={cn(
        'rounded-lg border border-line bg-surface p-5 shadow-sm',
        className,
      )}
      {...rest}
    />
  );
}

export function CardHeader({
  title,
  description,
  actions,
  level = 2,
}: {
  title: string;
  description?: string;
  actions?: ReactNode;
  level?: 2 | 3;
}) {
  const Heading = level === 2 ? 'h2' : 'h3';

  return (
    <div className="mb-4 flex flex-wrap items-start justify-between gap-3">
      <div>
        <Heading className="text-lg font-semibold text-ink">{title}</Heading>
        {description ? (
          <p className="mt-1 text-sm text-muted">{description}</p>
        ) : null}
      </div>
      {actions}
    </div>
  );
}
