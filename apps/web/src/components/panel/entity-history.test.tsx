import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { axe } from 'vitest-axe';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/lib/api';
import type { AuditLog } from '@/lib/audit';
import { EntityHistory } from './entity-history';

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
  actor: { id: 'u1', name: 'Ana', support: false },
  before: { name: 'Bia' },
  after: { name: 'Beatriz' },
  created_at: '2026-11-01T12:00:00Z',
  ...over,
});

describe('EntityHistory', () => {
  it('FND-008 loads the history of one record and shows it without the entity name', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [log()], next_cursor: null },
    });
    render(<EntityHistory entityType="client" entityId="e1" />);

    expect(await screen.findByText('Beatriz')).toBeInTheDocument();
    expect(call).toHaveBeenCalledWith(
      'GET',
      'audit-logs?entity_type=client&entity_id=e1&limit=20',
    );
    expect(screen.queryByText('Cliente')).not.toBeInTheDocument();
  });

  it('FND-008 marks the changes made in a support access', async () => {
    call.mockResolvedValue({
      status: 200,
      data: {
        data: [log({ actor: { id: 'u1', name: 'Ana', support: true } })],
        next_cursor: null,
      },
    });
    render(<EntityHistory entityType="client" entityId="e1" />);

    expect(await screen.findByText('suporte')).toBeInTheDocument();
  });

  it('FND-008 tells when there is nothing, and loads more by cursor', async () => {
    call
      .mockResolvedValueOnce({
        status: 200,
        data: { data: [log()], next_cursor: 'c2' },
      })
      .mockResolvedValueOnce({
        status: 200,
        data: {
          data: [log({ id: 'l2', after: { name: 'Outra' } })],
          next_cursor: null,
        },
      });
    const user = userEvent.setup();
    render(<EntityHistory entityType="client" entityId="e1" />);

    await user.click(
      await screen.findByRole('button', { name: 'Carregar mais' }),
    );

    expect(await screen.findByText('Outra')).toBeInTheDocument();
    expect(call).toHaveBeenLastCalledWith(
      'GET',
      'audit-logs?entity_type=client&entity_id=e1&limit=20&cursor=c2',
    );
    expect(screen.getByText('Beatriz')).toBeInTheDocument();
  });

  it('FND-008 shows the empty message and the permission error', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [], next_cursor: null },
    });
    const { unmount } = render(
      <EntityHistory entityType="client" entityId="e1" />,
    );
    expect(
      await screen.findByText(/Sem mudanças registradas/),
    ).toBeInTheDocument();
    unmount();

    failure = new ApiError('x', 403);
    render(<EntityHistory entityType="client" entityId="e1" />);
    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
  });

  it('FND-008 has no accessibility violations', async () => {
    call.mockResolvedValue({
      status: 200,
      data: { data: [log()], next_cursor: 'c' },
    });
    const { container } = render(
      <EntityHistory entityType="client" entityId="e1" />,
    );
    await screen.findByText('Beatriz');

    expect(await axe(container)).toHaveNoViolations();
  });
});
