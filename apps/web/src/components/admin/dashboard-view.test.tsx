import { render, screen, within } from '@testing-library/react';
import { axe } from 'vitest-axe';
import { describe, expect, it } from 'vitest';
import { adminMe } from './admin-fixtures';
import { DashboardView } from './dashboard-view';

const summary = {
  trialing: { value: 12, more: false },
  active: { value: 50, more: true },
  suspended: { value: 0, more: false },
  canceled: null,
  newLeads: { value: 3, more: false },
  activeCoupons: { value: 2, more: false },
};

describe('DashboardView', () => {
  it('CA-05 shows the counters, marks the capped ones with + and a dash when unavailable', () => {
    render(<DashboardView me={adminMe()} summary={summary} />);

    expect(
      screen.getByRole('heading', { name: 'Olá, Gustavo' }),
    ).toBeInTheDocument();
    const items = screen.getAllByRole('listitem');
    expect(within(items[0]!).getByText('12')).toBeInTheDocument();
    expect(within(items[1]!).getByText('50+')).toBeInTheDocument();
    expect(within(items[3]!).getByText('—')).toBeInTheDocument();
    expect(
      within(items[4]!).getByRole('link', { name: /Ver/ }),
    ).toHaveAttribute('href', '/admin/leads');
  });

  it('CA-14 has no accessibility violations', async () => {
    const { container } = render(
      <DashboardView me={adminMe()} summary={summary} />,
    );

    expect(await axe(container)).toHaveNoViolations();
  });
});
