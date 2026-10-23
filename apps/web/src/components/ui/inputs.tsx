import type {
  InputHTMLAttributes,
  SelectHTMLAttributes,
  TextareaHTMLAttributes,
} from 'react';
import { cn } from '@/lib/cn';
import { controlClass, Field, FieldMessages, useFieldIds } from './field';

type Common = { label: string; hint?: string; error?: string };

export function TextField({
  label,
  hint,
  error,
  className,
  required,
  ...input
}: Common & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <Field
      label={label}
      hint={hint}
      error={error}
      required={required}
      className={className}
    >
      {(control) => <input {...input} {...control} className={controlClass} />}
    </Field>
  );
}

export function TextareaField({
  label,
  hint,
  error,
  className,
  required,
  ...input
}: Common & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <Field
      label={label}
      hint={hint}
      error={error}
      required={required}
      className={className}
    >
      {(control) => (
        <textarea
          {...input}
          {...control}
          className={cn(controlClass, 'min-h-24 py-2')}
        />
      )}
    </Field>
  );
}

export function SelectField({
  label,
  hint,
  error,
  className,
  required,
  children,
  ...input
}: Common & SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <Field
      label={label}
      hint={hint}
      error={error}
      required={required}
      className={className}
    >
      {(control) => (
        <select {...input} {...control} className={controlClass}>
          {children}
        </select>
      )}
    </Field>
  );
}

/** Caixa de marcação com rótulo clicável e alvo de toque grande. */
export function CheckboxField({
  label,
  hint,
  error,
  className,
  ...input
}: Common & Omit<InputHTMLAttributes<HTMLInputElement>, 'type'>) {
  const { hintId, errorId, control } = useFieldIds(hint, error);

  return (
    <div className={cn('flex flex-col gap-1.5', className)}>
      <label
        htmlFor={control.id}
        className="flex min-h-11 items-start gap-3 text-base text-ink"
      >
        <input
          {...input}
          {...control}
          type="checkbox"
          className="mt-1 size-5 accent-brand-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
        />
        <span>{label}</span>
      </label>
      <FieldMessages
        hint={hint}
        error={error}
        hintId={hintId}
        errorId={errorId}
      />
    </div>
  );
}
