export type CaptchaMode = 'fake' | 'recaptcha';

export class CaptchaUnavailable extends Error {}

type Grecaptcha = {
  ready(callback: () => void): void;
  execute(siteKey: string, options: { action: string }): Promise<string>;
};

declare global {
  interface Window {
    grecaptcha?: Grecaptcha;
  }
}

/** Modo do anti-robô: `fake` (desenvolvimento) ou `recaptcha` (padrão, o seguro). */
export function captchaMode(): CaptchaMode {
  return process.env.NEXT_PUBLIC_CAPTCHA_MODE === 'fake' ? 'fake' : 'recaptcha';
}

function loadScript(siteKey: string): Promise<Grecaptcha> {
  if (window.grecaptcha) {
    return Promise.resolve(window.grecaptcha);
  }

  return new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`;
    script.async = true;
    script.onload = () =>
      window.grecaptcha
        ? resolve(window.grecaptcha)
        : reject(new CaptchaUnavailable());
    script.onerror = () => reject(new CaptchaUnavailable());
    document.head.appendChild(script);
  });
}

/** Token do anti-robô para enviar com o formulário. Lança `CaptchaUnavailable` se não der para obter. */
export async function getCaptchaToken(action: string): Promise<string> {
  if (captchaMode() === 'fake') {
    return 'captcha_ok';
  }

  const siteKey = process.env.NEXT_PUBLIC_RECAPTCHA_SITE_KEY;

  if (!siteKey) {
    throw new CaptchaUnavailable();
  }

  const grecaptcha = await loadScript(siteKey);

  return new Promise((resolve, reject) => {
    grecaptcha.ready(() => {
      grecaptcha
        .execute(siteKey, { action })
        .then(resolve, () => reject(new CaptchaUnavailable()));
    });
  });
}
