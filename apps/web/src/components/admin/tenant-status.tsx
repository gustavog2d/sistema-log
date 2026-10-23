import { t } from '@/i18n';
import type { TenantItem, TenantStatus } from '@/lib/admin-types';
import { Badge, type Tone } from '@/components/ui/badge';

const TONES: Record<TenantStatus, Tone> = {
  trialing: 'info',
  active: 'success',
  suspended: 'danger',
  canceled: 'neutral',
};

/** Situação da assinatura do negócio em um selo; sem assinatura mostra um traço explicado. */
export function TenantStatusBadge({ tenant }: { tenant: TenantItem }) {
  if (!tenant.status) {
    return <Badge tone="neutral">{t('admin.tenants.noSubscription')}</Badge>;
  }

  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <Badge tone={TONES[tenant.status]}>{t(`status.${tenant.status}`)}</Badge>
      {tenant.cancel_at_period_end ? (
        <Badge tone="warning">{t('status.cancelPending')}</Badge>
      ) : null}
    </span>
  );
}
