import type { ReactNode } from 'react';
import { Logo } from '@/components/ui/logo';

/** Entrada da equipe: um cartão centralizado com a marca. */
export default function AdminPublicLayout({
  children,
}: {
  children: ReactNode;
}) {
  return (
    <div className="flex min-h-dvh flex-col items-center bg-canvas px-4 py-10">
      <div className="mb-8">
        <Logo />
      </div>
      <main id="conteudo" tabIndex={-1} className="w-full outline-none">
        {children}
      </main>
    </div>
  );
}
