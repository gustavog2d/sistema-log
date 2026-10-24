import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { FinanceView } from '@/components/panel/finance-view';
import { t } from '@/i18n';
import { todayInBusiness } from '@/lib/appointment-types';
import { monthRange } from '@/lib/expense-types';
import type {
  FinanceCalendar,
  FinanceData,
  FinanceMonthly,
  FinanceSummary,
} from '@/lib/finance-types';
import { canSee } from '@/lib/panel-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('finance.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');

  if (!canSee(session.me.user.role, 'finance')) redirect('/');

  const context = await getPanelContext();
  const month = todayInBusiness().slice(0, 7);
  const range = monthRange(month);
  const [summary, monthly, calendar, goal] = await Promise.all([
    apiGet<FinanceSummary>(
      `finance/summary?from=${range.from}&to=${range.to}`,
      context,
    ),
    apiGet<FinanceMonthly>(`finance/monthly?to=${month}`, context),
    apiGet<FinanceCalendar>(`finance/calendar?month=${month}`, context),
    apiGet<{ monthly_goal_cents: number | null }>('finance/goal', context),
  ]);
  const data: FinanceData | null =
    summary.status === 200 &&
    summary.data &&
    monthly.status === 200 &&
    monthly.data &&
    calendar.status === 200 &&
    calendar.data
      ? {
          summary: summary.data,
          monthly: monthly.data,
          calendar: calendar.data,
        }
      : null;

  return (
    <FinanceView
      me={session.me}
      initial={data}
      goal={
        goal.status === 200 ? (goal.data?.monthly_goal_cents ?? null) : null
      }
      month={month}
    />
  );
}
