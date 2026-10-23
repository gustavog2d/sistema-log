import type { Metadata } from 'next';
import { ForgotForm } from '@/components/panel/recovery';
import { t } from '@/i18n';

export const metadata: Metadata = { title: t('recovery.forgotTitle') };

export default function Page() {
  return <ForgotForm />;
}
