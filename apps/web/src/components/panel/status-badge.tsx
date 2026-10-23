import { t } from '@/i18n';
import type { Subscription } from '@/lib/panel-types';
import { Badge, type Tone } from '@/components/ui/badge';

const TONES: Record<Subscription['status'], Tone> = {
  trialing: 'info',
  active: 'success',
  suspended: 'danger',
  canceled: 'neutral',
};

/** Situação da assinatura em um selo (com o cancelamento pedido em destaque). */
export function StatusBadge({ subscription }: { subscription: Subscription }) {
  return (
    <span className="inline-flex flex-wrap items-center gap-2">
      <Badge tone={TONES[subscription.status]}>
        {t(`status.${subscription.status}`)}
      </Badge>
      {subscription.cancel_at_period_end ? (
        <Badge tone="warning">{t('status.cancelPending')}</Badge>
      ) : null}
    </span>
  );
}
