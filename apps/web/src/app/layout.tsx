import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { appName } from '@/lib/brand';
import './globals.css';

export const metadata: Metadata = {
  title: appName(),
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="pt-BR">
      <body>{children}</body>
    </html>
  );
}
