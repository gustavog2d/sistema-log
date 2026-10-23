import { useId, type ReactNode } from 'react';
import { t } from '@/i18n';
import { cn } from '@/lib/cn';

export type FieldControlProps = {
  id: string;
  required?: boolean;
  'aria-invalid'?: true;
  'aria-describedby'?: string;
};

/** Ids e `aria-*` que ligam o controle à sua dica e ao seu erro. */
export function useFieldIds(hint?: string, error?: string) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;

  return {
    id,
    hintId,
    errorId,
    control: {
      id,
      'aria-invalid': error ? (true as const) : undefined,
      'aria-describedby':
        [hintId, errorId].filter(Boolean).join(' ') || undefined,
    },
  };
}

/** Dica e erro de um campo (texto com os ids certos). */
export function FieldMessages({
  hint,
  error,
  hintId,
  errorId,
}: {
  hint?: string;
  error?: string;
  hintId?: string;
  errorId?: string;
}) {
  return (
    <>
      {hint ? (
        <p id={hintId} className="text-sm text-muted">
          {hint}
        </p>
      ) : null}
      {error ? (
        <p id={errorId} className="text-sm font-medium text-danger-fg">
          {error}
        </p>
      ) : null}
    </>
  );
}

/** Rótulo, dica e erro ligados ao controle por id e `aria-describedby`. */
export function Field({
  label,
  hint,
  error,
  required,
  className,
  children,
}: {
  label: string;
  hint?: string;
  error?: string;
  required?: boolean;
  className?: string;
  children: (control: FieldControlProps) => ReactNode;
}) {
  const { id, hintId, errorId, control } = useFieldIds(hint, error);

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-sm font-medium text-ink">
        {label}
        {required ? (
          <span className="ml-1 text-xs font-normal text-muted">
            ({t('common.required')})
          </span>
        ) : null}
      </label>
      {children({ ...control, required })}
      <FieldMessages
        hint={hint}
        error={error}
        hintId={hintId}
        errorId={errorId}
      />
    </div>
  );
}

export const controlClass =
  'min-h-11 w-full rounded-md border border-control-border bg-surface px-3 text-base text-ink placeholder:text-slate-500 ' +
  'focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus disabled:cursor-not-allowed disabled:bg-slate-100 ' +
  'aria-[invalid=true]:border-danger-600';
