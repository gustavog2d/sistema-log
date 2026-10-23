'use client';

import { useState, type FormEvent } from 'react';
import { t } from '@/i18n';
import { ApiError, call } from '@/lib/api';
import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { TextField } from '@/components/ui/inputs';

type Setup = { secret: string; otpauth_uri: string };

/**
 * Ligar a verificação em duas etapas: pede o segredo à API, mostra para o aplicativo, confere o primeiro código e mostra
 * os códigos de recuperação uma única vez. Serve à conta (com sessão) e ao login da dona (com o token de configuração).
 */
export function TwoFactorSetup({ onDone }: { onDone: () => void }) {
  const [setup, setSetup] = useState<Setup | null>(null);
  const [recovery, setRecovery] = useState<string[] | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function start() {
    setBusy(true);
    setError(null);

    try {
      const { data } = await call<Setup>('POST', 'auth/2fa/setup');
      setSetup(data);
    } catch (failure) {
      setError(
        failure instanceof ApiError
          ? failure.message
          : t('common.genericError'),
      );
    } finally {
      setBusy(false);
    }
  }

  async function confirm(event: FormEvent) {
    event.preventDefault();
    setBusy(true);
    setError(null);

    try {
      const { data } = await call<{ recovery_codes: string[] }>(
        'POST',
        'auth/2fa/confirm',
        { body: { code: code.trim() } },
      );
      setRecovery(data.recovery_codes);
      setCode('');
    } catch (failure) {
      setError(
        failure instanceof ApiError && failure.status === 422
          ? t('account.setupInvalid')
          : failure instanceof ApiError && failure.status === 429
            ? t('login.rateLimited')
            : failure instanceof ApiError
              ? failure.message
              : t('common.genericError'),
      );
    } finally {
      setBusy(false);
    }
  }

  if (recovery) {
    return (
      <div className="space-y-4">
        <Alert tone="success">{t('account.twoFactorEnabled')}</Alert>
        <h3 className="text-lg font-semibold text-ink">
          {t('account.recoveryTitle')}
        </h3>
        <p className="text-sm text-muted">{t('account.recoveryText')}</p>
        <ul className="grid grid-cols-2 gap-2 rounded-md bg-slate-100 p-4 font-mono text-sm text-ink">
          {recovery.map((item) => (
            <li key={item}>{item}</li>
          ))}
        </ul>
        <Button onClick={onDone}>{t('account.recoveryDone')}</Button>
      </div>
    );
  }

  if (!setup) {
    return (
      <div className="space-y-3">
        {error ? <Alert tone="danger">{error}</Alert> : null}
        <Button onClick={() => void start()} loading={busy}>
          {t('account.setupStart')}
        </Button>
      </div>
    );
  }

  return (
    <form
      onSubmit={(event) => void confirm(event)}
      noValidate
      className="space-y-4"
    >
      <p className="text-sm text-muted">{t('account.setupInstructions')}</p>
      <div>
        <p className="text-sm font-medium text-ink">
          {t('account.setupSecret')}
        </p>
        <p
          className="mt-1 break-all rounded-md bg-slate-100 p-3 font-mono text-sm text-ink"
          data-testid="two-factor-secret"
        >
          {setup.secret}
        </p>
      </div>
      <details className="text-sm text-muted">
        <summary className="cursor-pointer font-medium text-brand-700">
          {t('account.setupUri')}
        </summary>
        <p className="mt-2 break-all font-mono">{setup.otpauth_uri}</p>
      </details>
      {error ? <Alert tone="danger">{error}</Alert> : null}
      <TextField
        label={t('account.setupCode')}
        value={code}
        onChange={(event) => setCode(event.target.value)}
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={6}
        required
      />
      <Button type="submit" loading={busy}>
        {t('account.setupConfirm')}
      </Button>
    </form>
  );
}
