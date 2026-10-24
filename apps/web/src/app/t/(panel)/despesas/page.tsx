import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { ExpensesView } from '@/components/panel/expenses-view';
import { t } from '@/i18n';
import {
  monthRange,
  type ExpenseCategory,
  type ExpenseList,
} from '@/lib/expense-types';
import { canSee } from '@/lib/panel-types';
import { todayInBusiness } from '@/lib/appointment-types';
import { getPanelContext } from '@/server/request-context';
import { getPanelSession } from '@/server/panel-session';
import { apiGet } from '@/server/session';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: t('expenses.title') };

export default async function Page() {
  const session = await getPanelSession();

  if (session.kind !== 'ok') redirect('/entrar');

  if (!canSee(session.me.user.role, 'expenses')) redirect('/');

  const context = await getPanelContext();
  const month = todayInBusiness().slice(0, 7);
  const range = monthRange(month);
  const [expenses, categories] = await Promise.all([
    apiGet<ExpenseList>(
      `expenses?from=${range.from}&to=${range.to}&limit=500`,
      context,
    ),
    apiGet<{ data: ExpenseCategory[] }>('expense-categories', context),
  ]);

  return (
    <ExpensesView
      me={session.me}
      initial={expenses.status === 200 ? expenses.data : null}
      categories={
        categories.status === 200 ? (categories.data?.data ?? []) : null
      }
      month={month}
    />
  );
}
