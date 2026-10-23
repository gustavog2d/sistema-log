'use client';

import {
  useEffect,
  useId,
  useRef,
  type KeyboardEvent,
  type ReactNode,
} from 'react';
import { t } from '@/i18n';
import { Button } from './button';

const FOCUSABLE =
  'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/**
 * Janela de diálogo: prende o foco, fecha com Esc e devolve o foco a quem a abriu.
 * Quem usa controla `open`; `onClose` é chamado no Esc, no botão de fechar e no clique no fundo.
 */
export function Dialog({
  open,
  onClose,
  title,
  description,
  children,
  footer,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: string;
  children?: ReactNode;
  footer?: ReactNode;
}) {
  const titleId = useId();
  const descriptionId = useId();
  const panel = useRef<HTMLDivElement>(null);
  const opener = useRef<HTMLElement | null>(null);

  useEffect(() => {
    if (!open) {
      return;
    }

    opener.current = document.activeElement as HTMLElement | null;
    // O foco começa no primeiro item do conteúdo (o botão de fechar é o primeiro da janela, não o mais útil).
    const items = panel.current?.querySelectorAll<HTMLElement>(FOCUSABLE);
    (items?.[1] ?? items?.[0] ?? panel.current)?.focus();

    // Atrás do diálogo a página não rola.
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = overflow;
      opener.current?.focus();
    };
  }, [open]);

  if (!open) {
    return null;
  }

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape') {
      event.stopPropagation();
      onClose();

      return;
    }

    if (event.key !== 'Tab' || !panel.current) {
      return;
    }

    const items = [...panel.current.querySelectorAll<HTMLElement>(FOCUSABLE)];
    const firstItem = items[0];
    const lastItem = items[items.length - 1];

    if (!firstItem || !lastItem) {
      event.preventDefault();

      return;
    }

    if (event.shiftKey && document.activeElement === firstItem) {
      event.preventDefault();
      lastItem.focus();
    } else if (!event.shiftKey && document.activeElement === lastItem) {
      event.preventDefault();
      firstItem.focus();
    }
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 p-0 sm:items-center sm:p-4"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          onClose();
        }
      }}
    >
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        tabIndex={-1}
        onKeyDown={onKeyDown}
        className="max-h-full w-full max-w-lg overflow-y-auto rounded-t-lg bg-surface p-6 shadow-xl outline-none sm:rounded-lg"
      >
        <div className="flex items-start justify-between gap-4">
          <h2 id={titleId} className="text-lg font-semibold text-ink">
            {title}
          </h2>
          <Button
            variant="ghost"
            onClick={onClose}
            aria-label={t('common.close')}
          >
            <span aria-hidden="true">×</span>
          </Button>
        </div>
        {description ? (
          <p id={descriptionId} className="mt-1 text-sm text-muted">
            {description}
          </p>
        ) : null}
        <div className="mt-4">{children}</div>
        {footer ? (
          <div className="mt-6 flex justify-end gap-3">{footer}</div>
        ) : null}
      </div>
    </div>
  );
}
