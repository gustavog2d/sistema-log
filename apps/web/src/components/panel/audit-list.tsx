import { t } from '@/i18n';
import { changesOf, formatDateTime, labelOf, type AuditLog } from '@/lib/audit';
import { Badge } from '@/components/ui/badge';

/** Linha do tempo de mudanças: quem, quando, o que e a mudança campo a campo (antes → depois). */
export function AuditList({
  logs,
  showEntity = true,
}: {
  logs: AuditLog[];
  showEntity?: boolean;
}) {
  return (
    <ol className="space-y-3">
      {logs.map((log) => (
        <li
          key={log.id}
          className="rounded-md border border-control-border bg-surface p-4"
        >
          <p className="flex flex-wrap items-center gap-2 text-sm text-ink">
            <span className="font-semibold">
              {log.actor
                ? log.actor.name || t('audit.unknownPerson')
                : t('audit.system')}
            </span>
            {log.actor?.support ? (
              <Badge tone="warning">{t('audit.support')}</Badge>
            ) : null}
            <Badge
              tone={
                log.action === 'created'
                  ? 'success'
                  : log.action === 'deleted'
                    ? 'danger'
                    : 'info'
              }
            >
              {t(`audit.action.${log.action}`)}
            </Badge>
            {showEntity ? (
              <span>{labelOf('audit.entity', log.entity_type)}</span>
            ) : null}
            <time className="ml-auto text-muted" dateTime={log.created_at}>
              {formatDateTime(log.created_at)}
            </time>
          </p>
          <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
            {changesOf(log).map((change) => (
              <div key={change.field} className="contents">
                <dt className="text-muted">{change.field}</dt>
                <dd className="break-words text-ink">
                  {change.before !== undefined && change.after !== undefined ? (
                    <>
                      <span className="sr-only">{t('audit.from')} </span>
                      <span className="text-muted line-through">
                        {change.before}
                      </span>
                      <span aria-hidden="true">{' → '}</span>
                      <span className="sr-only"> {t('audit.to')} </span>
                      <span className="font-medium">{change.after}</span>
                    </>
                  ) : (
                    (change.after ?? change.before)
                  )}
                </dd>
              </div>
            ))}
          </dl>
        </li>
      ))}
    </ol>
  );
}
