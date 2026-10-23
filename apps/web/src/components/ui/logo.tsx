import { appName, monogram } from '@/lib/brand';
import { cn } from '@/lib/cn';

/** Logotipo provisório: monograma do nome (de configuração) em um quadrado da cor da marca. */
export function Logo({
  name = appName(),
  className,
  inverted = false,
}: {
  name?: string;
  className?: string;
  inverted?: boolean;
}) {
  return (
    <span className={cn('inline-flex items-center gap-2', className)}>
      <svg
        aria-hidden="true"
        viewBox="0 0 32 32"
        className="size-8 shrink-0"
        focusable="false"
      >
        <rect width="32" height="32" rx="8" className="fill-brand-600" />
        <path
          d="M6 26 26 6v8L14 26z"
          className="fill-accent-500"
          opacity="0.9"
        />
        <text
          x="16"
          y="21"
          textAnchor="middle"
          fontSize="13"
          fontWeight="700"
          className="fill-surface"
        >
          {monogram(name)}
        </text>
      </svg>
      <span
        className={cn(
          'text-lg font-bold tracking-tight',
          inverted ? 'text-surface' : 'text-ink',
        )}
      >
        {name}
      </span>
    </span>
  );
}
