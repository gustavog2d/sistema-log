import type {
  HTMLAttributes,
  ReactNode,
  TdHTMLAttributes,
  ThHTMLAttributes,
} from 'react';
import { cn } from '@/lib/cn';

/** Tabela com legenda (obrigatória, pode ficar só para leitor de tela) e rolagem horizontal no celular. */
export function Table({
  caption,
  children,
  className,
}: {
  caption: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className="overflow-x-auto rounded-lg border border-line bg-surface">
      <table
        className={cn('w-full border-collapse text-left text-sm', className)}
      >
        <caption className="sr-only">{caption}</caption>
        {children}
      </table>
    </div>
  );
}

export function Th({
  className,
  scope = 'col',
  ...rest
}: ThHTMLAttributes<HTMLTableCellElement>) {
  return (
    <th
      scope={scope}
      className={cn(
        'border-b border-line bg-slate-50 px-4 py-3 font-semibold text-ink',
        className,
      )}
      {...rest}
    />
  );
}

export function Td({
  className,
  ...rest
}: TdHTMLAttributes<HTMLTableCellElement>) {
  return (
    <td
      className={cn(
        'border-b border-line px-4 py-3 align-middle text-ink',
        className,
      )}
      {...rest}
    />
  );
}

export function Tr(props: HTMLAttributes<HTMLTableRowElement>) {
  return <tr {...props} />;
}
