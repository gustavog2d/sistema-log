import { t } from '@/i18n';
import { Alert } from '@/components/ui/alert';

export function NoPermission() {
  return <Alert tone="warning">{t('panel.noPermission')}</Alert>;
}

export function LoadError() {
  return <Alert tone="danger">{t('panel.loadError')}</Alert>;
}
