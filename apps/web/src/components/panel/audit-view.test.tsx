import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type { AuditLog, AuditPage } from '@/lib/audit';
import { AuditView } from './audit-view';

const call = vi.fn();
// O espião do Vitest relança a rejeição numa cópia sem tratamento: a falha vai por uma função comum.
let failure: unknown = null;
vi.mock('@/lib/api', async (original) => ({
  ...(await original<typeof import('@/lib/api')>()),
  call: (...args: unknown[]) =>
    failure ? Promise.reject(failure) : call(...args),
}));

beforeEach(() => {
  failure = null;
  call.mockReset();
});

const log = (over: Partial<AuditLog> = {}): AuditLog => ({
  id: 'l1',
  entity_type: 'client',
  entity_id: 'e1',
  action: 'updated',
  actor: { id: 'u1', name: 'Ana Souza' },
  before: { name: 'Bia' },
  after: { name: 'Beatriz' },
  created_at: '2026-11-01T12:00:00Z',
  ...over,
});

const page = (cursor: string | null = null): AuditPage => ({
  data: [
    log(),
    log({
      id: 'l2',
      action: 'created',
      entity_type: 'expense',
      before: null,
      after: { price_cents: 5000 },
      actor: null,
    }),
  ],
  next_cursor: cursor,
});

describe('AuditView', () => {
  it('CA-11 lists who did what, with the change field by field', () => {
    render(<AuditView initial={page()} />);

    const items = screen.getAllByRole('listitem');
    expect(within(items[0]!).getByText('Ana Souza')).toBeInTheDocument();
    expect(within(items[0]!).getByText('alterou')).toBeInTheDocument();
    expect(within(items[0]!).getByText('Bia')).toBeInTheDocument();
    expect(within(items[0]!).getByText('Beatriz')).toBeInTheDocument();
    expect(within(items[1]!).getByText('Sistema')).toBeInTheDocument();
    expect(within(items[1]!).getByText('criou')).toBeInTheDocument();
    expect(within(items[1]!).getByText(/R\$\s?50,00/)).toBeInTheDocument();
  });

  it('CA-11 filters by type and sends only the filled fields', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [], next_cursor: null },
    });
    const user = userEvent.setup();
    render(<AuditView initial={page()} />);

    await user.selectOptions(
      screen.getByLabelText('Tipo de registro'),
      'client',
    );
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() =>
      expect(call).toHaveBeenCalledWith(
        'GET',
        'audit-logs?limit=20&entity_type=client',
      ),
    );
    expect(
      await screen.findByText('Nenhuma mudança registrada ainda.'),
    ).toBeInTheDocument();
  });

  it('CA-11 loads more keeping the applied filter even if the select changed', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [log({ id: 'l9', actor: { id: 'u', name: 'Caio' } })],
        next_cursor: 'c2',
      },
    });
    const user = userEvent.setup();
    render(<AuditView initial={page()} />);

    await user.selectOptions(
      screen.getByLabelText('Tipo de registro'),
      'client',
    );
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));
    await screen.findByText('Caio');
    await user.selectOptions(
      screen.getByLabelText('Tipo de registro'),
      'expense',
    );
    await user.click(screen.getByRole('button', { name: 'Carregar mais' }));

    await waitFor(() => expect(call).toHaveBeenCalledTimes(2));
    expect(call).toHaveBeenLastCalledWith(
      'GET',
      'audit-logs?limit=20&entity_type=client&cursor=c2',
    );
  });

  it('CA-11 shows an error for the first load and for a failed filter', async () => {
    failure = new ApiError('Falhou', 500);
    const user = userEvent.setup();
    render(<AuditView initial={null} />);

    expect(screen.getByRole('alert')).toHaveTextContent(
      'Não foi possível carregar o histórico',
    );
    await user.click(screen.getByRole('button', { name: 'Confirmar' }));
    expect(await screen.findByText('Falhou')).toBeInTheDocument();
  });

  it('CA-11 has no accessibility violations', async () => {
    const { container } = render(<AuditView initial={page('c1')} />);

    expect(await axe(container)).toHaveNoViolations();
  });
});
