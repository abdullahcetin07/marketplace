import type { Metadata } from 'next';
import Link from 'next/link';
import { Manrope } from 'next/font/google';
import { CategoryBar } from '@/components/CategoryBar';
import { HeaderActions } from '@/components/HeaderActions';
import { SessionProvider } from '@/components/SessionProvider';
import { SITE_URL } from '@/lib/site';
import './globals.css';

// The approved §2.3 face, self-hosted (latin-ext covers Turkish diacritics).
const manrope = Manrope({
  variable: '--font-manrope',
  subsets: ['latin', 'latin-ext'],
  weight: ['400', '500', '600', '700', '800'],
  display: 'swap',
});

/**
 * The shell renders per request (§2.1). Its category bar reads the live tree, and
 * the header's cart/session is client-side — nothing here is safe to freeze into
 * static HTML at build time, so the whole app opts out of it. Prices and stock on
 * the pages within are the same story.
 */
export const dynamic = 'force-dynamic';

export const metadata: Metadata = {
  metadataBase: new URL(SITE_URL),
  title: {
    default: 'Raftabul',
    template: '%s — Raftabul',
  },
  description: 'Binlerce satıcı, tek pazar yeri.',
  // Default social-share card for every page that doesn't set its own (product and
  // store pages override `images` with their own). Drop a 1200×630 banner at
  // public/og-default.jpg and it fills the preview site-wide.
  openGraph: {
    siteName: 'Raftabul',
    type: 'website',
    locale: 'tr_TR',
    images: [{ url: '/og-default.jpg', width: 1200, height: 630, alt: 'Raftabul' }],
  },
  twitter: { card: 'summary_large_image' },
};

const footerCols = [
  { h: 'Kurumsal', items: ['Hakkımızda', 'Satıcı Ol', 'Kariyer', 'İletişim'] },
  { h: 'Yardım', items: ['Sipariş Takibi', 'İade & Değişim', 'Kargo', 'Sıkça Sorulanlar'] },
  { h: 'Güven', items: ['Gizlilik', 'KVKK', 'Kullanım Şartları', 'Güvenli Alışveriş'] },
];

/**
 * The shell every page renders inside.
 *
 * TURKISH-FIRST (§2.3): `lang="tr"` is not decoration — it drives hyphenation,
 * the browser's translation offer and how a screen reader pronounces the page.
 *
 * THE PROVIDER WRAPS EVERYTHING, and only the pieces that need it are client
 * components. `SessionProvider` is a client boundary, but the pages it contains
 * stay server-rendered — passing them as `children` means React composes them on
 * the server and the provider merely hydrates around them.
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

          <header className="sticky top-0 z-40 border-b border-ink-200 bg-white/95 backdrop-blur dark:border-ink-800 dark:bg-ink-950/95">
            <div className="mx-auto flex w-full max-w-page items-center gap-4 px-4 py-3 sm:gap-6">
              <Link href="/" className="shrink-0 text-2xl font-extrabold tracking-tight">
                <span className="text-brand-500">raf</span>tabul
                <span className="ml-2 hidden rounded-md bg-brand-50 px-1.5 py-0.5 align-middle text-[.6rem] font-extrabold tracking-wide text-brand-700 dark:bg-brand-500/15 sm:inline">
                  SAĞLIK
                </span>
              </Link>

              {/* search — a real GET into the listing, so it works without JS and the
                  URL is shareable (Storefront.md §2.2). */}
              <form action="/urunler" className="hidden min-w-0 flex-1 items-center rounded-xl border-2 border-ink-200 bg-ink-50 focus-within:border-brand-500 dark:border-ink-700 dark:bg-ink-900 md:flex">
                <input
                  name="q"
                  placeholder="Ürün, marka veya kategori ara"
                  className="min-w-0 flex-1 bg-transparent px-4 py-2.5 text-[.94rem] outline-none placeholder:text-ink-400"
                  aria-label="Ürün ara"
                />
                <button className="grid place-items-center rounded-r-[10px] bg-brand-500 px-5 py-2.5 text-white hover:bg-brand-600" aria-label="Ara">
                  <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                    <circle cx="11" cy="11" r="7" />
                    <path d="m20 20-3.5-3.5" />
                  </svg>
                </button>
              </form>

              <div className="ml-auto shrink-0">
                <HeaderActions />
              </div>
            </div>
          </header>

          <CategoryBar />

          <main className="mx-auto w-full max-w-page flex-1 px-4 py-8">{children}</main>

          <footer className="mt-8 border-t border-ink-200 bg-white pt-10 pb-6 dark:border-ink-800 dark:bg-ink-950">
            <div className="mx-auto grid w-full max-w-page grid-cols-2 gap-8 px-4 md:grid-cols-[1.4fr_1fr_1fr_1fr]">
              <div>
                <span className="text-xl font-extrabold">
                  <span className="text-brand-500">raf</span>tabul
                </span>
                <p className="my-3 max-w-[32ch] text-sm text-ink-500">
                  Onaylı eczane ve mağazaların orijinal sağlık &amp; bakım ürünlerini buluşturan pazaryeri.
                </p>
                <div className="flex gap-2">
                  {['VISA', 'MASTERCARD', 'TROY'].map((c) => (
                    <span key={c} className="grid h-[26px] place-items-center rounded-md border border-ink-200 px-2.5 text-[.68rem] font-extrabold text-ink-500 dark:border-ink-700">
                      {c}
                    </span>
                  ))}
                </div>
              </div>
              {footerCols.map((col) => (
                <div key={col.h}>
                  <h5 className="mb-3 text-[.76rem] font-bold uppercase tracking-wider text-ink-400">{col.h}</h5>
                  <ul className="flex flex-col gap-2.5">
                    {col.items.map((i) => (
                      <li key={i}>
                        <Link href="#" className="text-sm text-ink-500 hover:text-brand-600">{i}</Link>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
            <div className="mx-auto mt-7 flex w-full max-w-page flex-wrap justify-between gap-2 border-t border-ink-100 px-4 pt-4 text-[.8rem] text-ink-400 dark:border-ink-800">
              <span>© {new Date().getFullYear()} Raftabul. Tüm hakları saklıdır.</span>
              <span>Orijinal ürün garantisi · Onaylı satıcılar</span>
            </div>
          </footer>
        </SessionProvider>
      </body>
    </html>
  );
}
