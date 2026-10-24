import { describe, expect, it } from 'vitest';
import { changesOf, formatAuditValue, labelOf, type AuditLog } from './audit';

const log = (over: Partial<AuditLog> = {}): AuditLog => ({
  id: 'l1',
  entity_type: 'client',
  entity_id: 'e1',
  action: 'updated',
  actor: { id: 'u1', name: 'Ana', support: false },
  before: { name: 'Bia', price_cents: 5000 },
  after: { name: 'Beatriz', price_cents: 6000 },
  created_at: '2026-11-01T12:00:00Z',
  ...over,
});

describe('audit helpers', () => {
  it('FND-008 labels known entities and fields and falls back to the key', () => {
    expect(labelOf('audit.entity', 'client')).toBe('Cliente');
    expect(labelOf('audit.field', 'name')).toBe('Nome');
    expect(labelOf('audit.field', 'campo_novo')).toBe('campo novo');
  });

  it('FND-008 formats booleans, money, dates and empty values', () => {
    expect(formatAuditValue('active', true)).toBe('Sim');
    expect(formatAuditValue('active', false)).toBe('Não');
    expect(formatAuditValue('price_cents', 5000)).toMatch(/R\$\s?50,00/);
    expect(formatAuditValue('starts_at', '2026-11-01T12:00:00Z')).toMatch(
      /01\/11\/2026/,
    );
    expect(formatAuditValue('notes', null)).toBe('vazio');
    expect(formatAuditValue('notes', '')).toBe('vazio');
    expect(formatAuditValue('tags', ['a', 'b'])).toBe('a, b');
    expect(formatAuditValue('ranges', [])).toBe('vazio');
  });

  it('FND-008 describes an update field by field and a creation or deletion with one side', () => {
    expect(changesOf(log())).toEqual([
      { field: 'Nome', before: 'Bia', after: 'Beatriz' },
      {
        field: 'Preço',
        before: expect.stringMatching(/50,00/),
        after: expect.stringMatching(/60,00/),
      },
    ]);
    expect(
      changesOf(
        log({ action: 'created', before: null, after: { name: 'Bia' } }),
      ),
    ).toEqual([{ field: 'Nome', before: undefined, after: 'Bia' }]);
    expect(
      changesOf(
        log({ action: 'deleted', after: null, before: { name: 'Bia' } }),
      ),
    ).toEqual([{ field: 'Nome', before: 'Bia', after: undefined }]);
  });
});
