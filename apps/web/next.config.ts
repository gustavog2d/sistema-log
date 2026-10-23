import type { NextConfig } from 'next';

const production = process.env.NODE_ENV === 'production';

// Os modos de teste (cartão e anti-robô) são embutidos no JavaScript na compilação: em produção a compilação para.
for (const name of ['NEXT_PUBLIC_PAYMENT_MODE', 'NEXT_PUBLIC_CAPTCHA_MODE']) {
  if (production && process.env[name] === 'fake') {
    throw new Error(`${name}=fake não pode ser usado em produção.`);
  }
}

const GOOGLE =
  'https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/';

/** Política de conteúdo: só o próprio site, mais o reCAPTCHA do Google. O Next precisa de scripts inline (e de eval no desenvolvimento). */
export const contentSecurityPolicy = [
  "default-src 'self'",
  `script-src 'self' 'unsafe-inline' ${production ? '' : "'unsafe-eval' "}${GOOGLE}`,
  "style-src 'self' 'unsafe-inline'",
  "img-src 'self' data: https://www.gstatic.com/recaptcha/",
  "font-src 'self'",
  "connect-src 'self'",
  `frame-src ${GOOGLE}`,
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
].join('; ');

export const securityHeaders = [
  { key: 'Content-Security-Policy', value: contentSecurityPolicy },
  { key: 'X-Frame-Options', value: 'DENY' },
  { key: 'X-Content-Type-Options', value: 'nosniff' },
  { key: 'Referrer-Policy', value: 'strict-origin-when-cross-origin' },
  {
    key: 'Permissions-Policy',
    value: 'camera=(), microphone=(), geolocation=(), payment=()',
  },
  ...(production
    ? [
        {
          key: 'Strict-Transport-Security',
          value: 'max-age=31536000; includeSubDomains',
        },
      ]
    : []),
];

const config: NextConfig = {
  transpilePackages: ['@app/api-client', '@app/ui-tokens'],
  poweredByHeader: false,
  async headers() {
    return [{ source: '/:path*', headers: securityHeaders }];
  },
};

export default config;
