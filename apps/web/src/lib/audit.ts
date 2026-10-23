import { t, type MessageKey } from '@/i18n';
import { formatBRL } from '@/lib/format';

export type AuditLog = {
  id: string;
  entity_type: string;
  entity_id: string;
  action: 'created' | 'updated' | 'deleted';
  actor: { id: string | null; name: string; support: boolean } | null;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
  created_at: string;
};

export type AuditPage = { data: AuditLog[]; next_cursor: string | null };

/** Texto traduzido de uma chave que pode não existir (tipo de registro ou campo novo): cai na própria chave. */
export function labelOf(
  prefix: 'audit.entity' | 'audit.field',
  key: string,
): string {
  const text = t(`${prefix}.${key}` as MessageKey) as string | undefined;

  return typeof text === 'string' ? text : key.replaceAll('_', ' ');
}

const ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/;

/** Fuso de exibição (o fuso do negócio ainda é sempre o de Brasília; fixo para o servidor e o navegador mostrarem o mesmo texto). */
const ZONE = 'America/Sao_Paulo';

/** Data e hora de um instante ISO no fuso do negócio, como `01/11/2026 09:00`. */
export function formatDateTime(iso: string): string {
  return new Intl.DateTimeFormat('pt-BR', {
    timeZone: ZONE,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
    .format(new Date(iso))
    .replace(',', '');
}

/** Valor de um campo para a tela: sim/não, dinheiro em reais (campos `*_cents`), datas e "vazio". */
export function formatAuditValue(field: string, value: unknown): string {
  if (value === null || value === undefined || value === '') {
    return t('audit.empty_value');
  }

  if (typeof value === 'boolean') {
    return t(value ? 'audit.yes' : 'audit.no');
  }

  if (typeof value === 'number' && field.endsWith('_cents')) {
    return formatBRL(value);
  }

  // Só campos de data (nome terminado em `_at`): um texto livre que pareça data continua como foi digitado.
  if (typeof value === 'string' && field.endsWith('_at') && ISO.test(value)) {
    return formatDateTime(value);
  }

  return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

export type Change = { field: string; before?: string; after?: string };

/** Mudanças de um registro, campo a campo (criação e exclusão mostram só um lado). */
export function changesOf(log: AuditLog): Change[] {
  const fields = Object.keys({ ...(log.before ?? {}), ...(log.after ?? {}) });

  return fields.map((field) => ({
    field: labelOf('audit.field', field),
    before:
      log.before && field in log.before
        ? formatAuditValue(field, log.before[field])
        : undefined,
    after:
      log.after && field in log.after
        ? formatAuditValue(field, log.after[field])
        : undefined,
  }));
}
