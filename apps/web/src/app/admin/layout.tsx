import type { Metadata } from 'next';
import type { ReactNode } from 'react';

// A área da equipe não aparece em buscadores.
export const metadata: Metadata = {
  robots: { index: false, follow: false },
  // Nada do endereço da equipe vai a outro site (a nova aba do acesso de suporte é aberta sem `noopener`).
  referrer: 'no-referrer',
};

export default function AdminLayout({ children }: { children: ReactNode }) {
  return children;
}
