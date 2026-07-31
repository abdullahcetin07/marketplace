import type { Metadata } from 'next';
import Link from 'next/link';
import { HeaderActions } from '@/components/HeaderActions';
import { SessionProvider } from '@/components/SessionProvider';
import './globals.css';

export const metadata: Metadata = {
  title: {
    default: 'Raftabul',
    template: '%s — Raftabul',
  },
  description: 'Binlerce satıcı, tek pazar yeri.',
};

/**
 * The shell every page renders inside.
 *
 * TURKISH-FIRST (§2.3): `lang="tr"` is not decoration — it drives hyphenation,
 * the browser's translation offer and how a screen reader pronounces the page.
 *
 * THE PROVIDER WRAPS EVERYTHING, and only the pieces that need it are client
 * components. `SessionProvider` is a client boundary, but the pages it contains
 * stay server-rendered — passing them as `children` means React composes them on
 * the server and the provider merely hydrates around them. That is what keeps the
 * listing and product pages indexable while the header knows who you are.
 */
export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="tr">
      <body className="flex min-h-screen flex-col">
        <SessionProvider>
        <header className="border-b border-ink-200 dark:border-ink-800">
          <div className="mx-auto flex w-full max-w-7xl items-center gap-6 px-4 py-4">
            <Link href="/" className="text-xl font-bold tracking-tight">
              <span className="text-brand-500">raf</span>tabul
            </Link>

            <nav className="text-sm">
              <Link href="/urunler" className="hover:text-brand-600">
                Tüm ürünler
              </Link>
            </nav>

            <div className="ml-auto">
              <HeaderActions />
            </div>
          </div>
        </header>

        <main className="mx-auto w-full max-w-7xl flex-1 px-4 py-8">{children}</main>

        <footer className="border-t border-ink-200 py-8 text-center text-sm text-ink-500 dark:border-ink-800">
          © {new Date().getFullYear()} Raftabul
        </footer>
        </SessionProvider>
      </body>
    </html>
  );
}
