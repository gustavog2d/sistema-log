'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import type { Me } from '@/lib/panel-types';
import { PageHeader } from '@/components/layout/page-header';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardHeader } from '@/components/ui/card';
import { Dialog } from '@/components/ui/dialog';
import { TextField } from '@/components/ui/inputs';
import {
  MAX_PASSWORD_BYTES,
  MIN_PASSWORD,
  passwordBytes,
} from '@/components/site/signup-model';
import { TwoFactorSetup } from './two-factor-setup';

/** Minha conta: dados, troca de senha, verificação em duas etapas e saída. */
export function AccountView({ me }: { me: Me }) {
  const router = useRouter();
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [notice, setNotice] = useState<{
    tone: 'success' | 'danger';
    text: string;
  } | null>(null);
  const [busy, setBusy] = useState(false);
  const [disableOpen, setDisableOpen] = useState(false);
  const [disablePassword, setDisablePassword] = useState('');
  const [disableError, setDisableError] = useState<string | undefined>();
  const blocked = me.impersonating;

  async function changePassword(event: FormEvent) {
    event.preventDefault();
    const found: Record<string, string> = {};

    if (!current) found.current_password = t('signup.required');
    if (next.length < MIN_PASSWORD) found.password = t('account.newHint');
    else if (passwordBytes(next) > MAX_PASSWORD_BYTES)
      found.password = t('signup.passwordTooLong');

    if (Object.keys(found).length > 0) {
      setErrors(found);

      return;
    }

    setErrors({});
    setBusy(true);
    setNotice(null);

    try {
      await call('POST', 'auth/password/change', {
        body: { current_password: current, password: next },
      });
      setCurrent('');
      setNext('');
      setNotice({ tone: 'success', text: t('account.passwordSaved') });
    } catch (failure) {
      if (failure instanceof ApiError && failure.status === 423) {
        setNotice({ tone: 'danger', text: t('account.locked') });
      } else if (
        failure instanceof ApiError &&
        failure.fieldErrors.current_password
      ) {
        setErrors({ current_password: t('account.wrongCurrent') });
      } else if (failure instanceof ApiError && failure.fieldErrors.password) {
        setErrors({ password: failure.fieldErrors.password });
      } else if (failure instanceof ApiError && failure.status === 429) {
        setNotice({ tone: 'danger', text: t('login.rateLimited') });
      } else {
        setNotice({
          tone: 'danger',
          text:
            failure instanceof ApiError
              ? failure.message
              : t('common.genericError'),
        });
      }
    } finally {
      setBusy(false);
    }
  }

  async function disable() {
    if (!disablePassword) {
      setDisableError(t('signup.required'));

      return;
    }

    setBusy(true);
    setDisableError(undefined);

    try {
      await call('POST', 'auth/2fa/disable', {
        body: { current_password: disablePassword },
      });
      setDisableOpen(false);
      setDisablePassword('');
      setNotice({ tone: 'success', text: t('account.disabled') });
      router.refresh();
    } catch (failure) {
      setDisableError(
        failure instanceof ApiError && failure.status === 423
          ? t('account.locked')
          : failure instanceof ApiError && failure.fieldErrors.current_password
            ? t('account.wrongCurrent')
            : failure instanceof ApiError
              ? failure.message
              : t('common.genericError'),
      );
    } finally {
      setBusy(false);
    }
  }

  async function leave(path: 'auth/logout' | 'auth/logout-all') {
    try {
      await call('POST', path);
    } catch {
      // Mesmo que a chamada falhe, a pessoa sai da tela: o cookie vence sozinho.
    } finally {
      router.replace('/entrar');
      router.refresh();
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('account.title')} />
      {notice ? <Alert tone={notice.tone}>{notice.text}</Alert> : null}
      {blocked ? (
        <Alert tone="info">{t('account.blockedSupport')}</Alert>
      ) : null}

      <Card>
        <CardHeader title={t('account.profile')} />
        <dl className="grid gap-3 text-sm sm:grid-cols-3">
          <div>
            <dt className="text-muted">{t('account.name')}</dt>
            <dd className="font-semibold text-ink">{me.user.name}</dd>
          </div>
          <div>
            <dt className="text-muted">{t('account.email')}</dt>
            <dd className="font-semibold text-ink">{me.user.email}</dd>
          </div>
          <div>
            <dt className="text-muted">{t('account.role')}</dt>
            <dd className="font-semibold text-ink">
              {t(`roles.${me.user.role}`)}
            </dd>
          </div>
        </dl>
      </Card>

      <Card>
        <CardHeader title={t('account.passwordTitle')} />
        <form
          onSubmit={(event) => void changePassword(event)}
          noValidate
          className="flex max-w-md flex-col gap-4"
        >
          <TextField
            label={t('account.current')}
            type="password"
            value={current}
            onChange={(event) => setCurrent(event.target.value)}
            error={errors.current_password}
            autoComplete="current-password"
            disabled={blocked}
            required
          />
          <TextField
            label={t('account.new')}
            hint={t('account.newHint')}
            type="password"
            value={next}
            onChange={(event) => setNext(event.target.value)}
            error={errors.password}
            autoComplete="new-password"
            disabled={blocked}
            required
          />
          <div>
            <Button type="submit" loading={busy} disabled={blocked}>
              {t('account.passwordSave')}
            </Button>
          </div>
        </form>
      </Card>

      <Card>
        <CardHeader
          title={t('account.twoFactor')}
          actions={
            <Badge tone={me.two_factor_enabled ? 'success' : 'neutral'}>
              {t(
                me.two_factor_enabled
                  ? 'account.twoFactorOn'
                  : 'account.twoFactorOff',
              )}
            </Badge>
          }
        />
        <p className="mb-4 text-sm text-muted">{t('account.twoFactorText')}</p>
        {blocked ? null : me.two_factor_enabled ? (
          <Button
            variant="secondary"
            onClick={() => {
              setDisableError(undefined);
              setDisablePassword('');
              setDisableOpen(true);
            }}
          >
            {t('account.disable')}
          </Button>
        ) : (
          <TwoFactorSetup onDone={() => router.refresh()} />
        )}
      </Card>

      <Card>
        <CardHeader
          title={t('account.sessions')}
          description={t('account.logoutAllText')}
        />
        <div className="flex flex-wrap gap-3">
          <Button variant="secondary" onClick={() => void leave('auth/logout')}>
            {t('account.logout')}
          </Button>
          <Button
            variant="secondary"
            disabled={blocked}
            onClick={() => void leave('auth/logout-all')}
          >
            {t('account.logoutAll')}
          </Button>
        </div>
      </Card>

      <Dialog
        open={disableOpen}
        onClose={() => setDisableOpen(false)}
        title={t('account.disableTitle')}
        description={t('account.disableBody')}
        footer={
          <>
            <Button variant="secondary" onClick={() => setDisableOpen(false)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="danger"
              loading={busy}
              onClick={() => void disable()}
            >
              {t('account.disableConfirm')}
            </Button>
          </>
        }
      >
        <TextField
          label={t('account.current')}
          type="password"
          value={disablePassword}
          onChange={(event) => setDisablePassword(event.target.value)}
          error={disableError}
          autoComplete="current-password"
          required
        />
      </Dialog>
    </div>
  );
}
