import type { Metadata } from 'next';
import type { ReactNode } from 'react';

// A área da equipe não aparece em buscadores.
export const metadata: Metadata = { robots: { index: false, follow: false } };

export default function AdminLayout({ children }: { children: ReactNode }) {
  return children;
}
