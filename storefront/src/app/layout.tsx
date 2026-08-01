import type { Metadata } from 'next';
import Link from 'next/link';
import { Manrope } from 'next/font/google';
import { HeaderActions } from '@/components/HeaderActions';
import { SessionProvider } from '@/components/SessionProvider';
import './globals.css';

// The approved §2.3 face, self-hosted (latin-ext covers Turkish diacritics).
const manrope = Manrope({
  variable: '--font-manrope',
  subsets: ['latin', 'latin-ext'],
  weight: ['400', '500', '600', '700', '800'],
  display: 'swap',
});

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
    <html lang="tr" className={manrope.variable}>
      <body className="flex min-h-screen flex-col antialiased">
        <SessionProvider>
          <div className="bg-ink-950 text-ink-100">
            <div className="mx-auto flex w-full max-w-page items-center justify-between px-4 py-1.5 text-xs">
              <span>Onaylı satıcılardan orijinal ürün · 200 TL üzeri kargo bedava</span>
              <Link href="/urunler" className="hidden text-ink-300 hover:text-white sm:block">
                Tüm ürünler →
              </Link>
            </div>
          </div>

          <header className="sticky top-0 z-40 border-b border-ink-200 bg-white/90 backdrop-blur dark:border-ink-800 dark:bg-ink-950/90">
            <div className="mx-auto flex w-full max-w-page items-center gap-6 px-4 py-3.5">
              <Link href="/" className="text-2xl font-extrabold tracking-tight">
                <span className="text-brand-500">raf</span>tabul
                <span className="ml-2 hidden rounded-md bg-brand-50 px-1.5 py-0.5 align-middle text-[.6rem] font-extrabold tracking-wide text-brand-700 dark:bg-brand-500/15 sm:inline">
                  SAĞLIK
                </span>
              </Link>

              <nav className="hidden text-sm font-semibold text-ink-600 dark:text-ink-300 sm:block">
                <Link href="/urunler" className="rounded-lg px-3 py-2 hover:bg-brand-50 hover:text-brand-700 dark:hover:bg-ink-900">
                  Tüm ürünler
                </Link>
              </nav>

              <div className="ml-auto">
                <HeaderActions />
              </div>
            </div>
          </header>

          <main className="mx-auto w-full max-w-page flex-1 px-4 py-8">{children}</main>

          <footer className="border-t border-ink-200 bg-white py-10 dark:border-ink-800 dark:bg-ink-950">
            <div className="mx-auto flex w-full max-w-page flex-col gap-2 px-4 text-sm text-ink-500">
              <span className="text-lg font-extrabold text-ink-900 dark:text-ink-100">
                <span className="text-brand-500">raf</span>tabul
              </span>
              <span>Onaylı eczane ve mağazaların orijinal sağlık &amp; bakım ürünlerini buluşturan pazaryeri.</span>
              <span className="mt-2">© {new Date().getFullYear()} Raftabul · Orijinal ürün garantisi</span>
            </div>
          </footer>
        </SessionProvider>
      </body>
    </html>
  );
}
