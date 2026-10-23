import type { Metadata } from 'next';
import { ResetForm } from '@/components/panel/recovery';
import { t } from '@/i18n';

export const metadata: Metadata = {
  title: t('recovery.resetTitle'),
  referrer: 'no-referrer',
};

export default async function Page({
  searchParams,
}: {
  searchParams: Promise<{ token?: string; email?: string }>;
}) {
  const { token, email } = await searchParams;

  return <ResetForm token={token} email={email} />;
}
