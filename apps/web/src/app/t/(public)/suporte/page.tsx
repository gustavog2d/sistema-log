import type { Metadata } from 'next';
import { SupportEntry } from '@/components/panel/support-entry';
import { t } from '@/i18n';

export const metadata: Metadata = {
  title: t('support.title'),
  referrer: 'no-referrer',
};

export default function Page() {
  return <SupportEntry />;
}
