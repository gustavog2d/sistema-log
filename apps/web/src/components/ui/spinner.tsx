import { t } from '@/i18n';
import { cn } from '@/lib/cn';

export function Spinner({
  className,
  label = t('common.loading'),
}: {
  className?: string;
  label?: string;
}) {
  return (
    <span role="status" className="inline-flex items-center">
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        className={cn('size-5 animate-spin', className)}
        fill="none"
      >
        <circle
          cx="12"
          cy="12"
          r="9"
          stroke="currentColor"
          strokeWidth="3"
          opacity="0.25"
        />
        <path
          d="M21 12a9 9 0 0 0-9-9"
          stroke="currentColor"
          strokeWidth="3"
          strokeLinecap="round"
        />
      </svg>
      <span className="sr-only">{label}</span>
    </span>
  );
}
