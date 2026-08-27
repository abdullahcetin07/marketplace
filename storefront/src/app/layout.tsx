import type { Metadata } from 'next';
import Link from 'next/link';
import { Manrope } from 'next/font/google';
import { CategoryBar } from '@/components/CategoryBar';
import { CookieConsent } from '@/components/CookieConsent';
import { GoogleTagManager } from '@/components/GoogleTagManager';
import { HeaderActions } from '@/components/HeaderActions';
import { SearchAutocomplete } from '@/components/SearchAutocomplete';
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
  {
    h: 'Kurumsal',
    items: [
      { label: 'Hakkımızda', href: '/sayfa/hakkimizda' },
      { label: 'Satıcı Ol', href: '/sayfa/satici-ol' },
      { label: 'Kariyer', href: '/sayfa/kariyer' },
      { label: 'İletişim', href: '/sayfa/iletisim' },
    ],
  },
  {
    h: 'Yardım',
    items: [
      { label: 'Sipariş Takibi', href: '/hesap/siparislerim' },
      { label: 'İade & Değişim', href: '/sayfa/iade-degisim' },
      { label: 'Kargo', href: '/sayfa/kargo' },
      { label: 'Sıkça Sorulanlar', href: '/sayfa/sss' },
    ],
  },
  {
    h: 'Güven',
    items: [
      { label: 'Gizlilik', href: '/sayfa/gizlilik' },
      { label: 'KVKK', href: '/sayfa/kvkk' },
      { label: 'Kullanım Şartları', href: '/sayfa/kullanim-sartlari' },
      { label: 'Güvenli Alışveriş', href: '/sayfa/guvenli-alisveris' },
    ],
  },
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
        <GoogleTagManager />
        <SessionProvider>
          <div className="bg-ink-950 text-ink-100">
            <div className="mx-auto flex w-full max-w-page items-center px-4 py-1.5 text-xs">
              <span>Onaylı satıcılardan orijinal ürün · Tüm siparişlerde kargo bedava</span>
            </div>
          </div>

          <header className="sticky top-0 z-40 border-b border-ink-200 bg-white/95 backdrop-blur dark:border-ink-800 dark:bg-ink-950/95">
            <div className="mx-auto flex w-full max-w-page items-center gap-4 px-4 py-3 sm:gap-6">
              <Link href="/" className="shrink-0" aria-label="Raftabul ana sayfa">
                {/* Monochrome black wordmark on transparent — dark:invert flips it to
                    white so it stays legible on the near-black header in dark mode. */}
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img src="/logo.png" alt="Raftabul" className="h-8 w-auto dark:invert sm:h-9" />
              </Link>

              {/* search — a real GET into the listing (works without JS, shareable URL,
                  Storefront.md §2.2), enhanced with type-ahead suggestions (ADR-090). */}
              <SearchAutocomplete />

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
                <div className="mt-4 flex gap-3">
                  <a href="https://www.instagram.com/raftabulcom/" target="_blank" rel="noopener noreferrer me" aria-label="Instagram" className="grid h-9 w-9 place-items-center rounded-full border border-ink-200 text-ink-500 transition hover:border-brand-400 hover:text-brand-600 dark:border-ink-700">
                    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                      <rect x="2" y="2" width="20" height="20" rx="5" />
                      <path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z" />
                      <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" />
                    </svg>
                  </a>
                  <a href="https://www.facebook.com/raftabul/" target="_blank" rel="noopener noreferrer me" aria-label="Facebook" className="grid h-9 w-9 place-items-center rounded-full border border-ink-200 text-ink-500 transition hover:border-brand-400 hover:text-brand-600 dark:border-ink-700">
                    <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                      <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" />
                    </svg>
                  </a>
                </div>
              </div>
              {footerCols.map((col) => (
                <div key={col.h}>
                  <h5 className="mb-3 text-[.76rem] font-bold uppercase tracking-wider text-ink-400">{col.h}</h5>
                  <ul className="flex flex-col gap-2.5">
                    {col.items.map((item) => (
                      <li key={item.href}>
                        <Link href={item.href} className="text-sm text-ink-500 hover:text-brand-600">
                          {item.label}
                        </Link>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
            <div className="mx-auto mt-7 w-full max-w-page border-t border-ink-100 px-4 pt-4 dark:border-ink-800">
              {/* Regulatory disclaimer — kept quiet, but present site-wide: these are
                  cosmetics and food supplements, not medicine. */}
              <p className="mb-3 text-[.72rem] leading-relaxed text-ink-400">
                Kozmetik ve takviye edici gıdalar hastalıkların teşhis, tedavi ve önlenmesi
                amacıyla kullanılamaz. Sağlık sorunlarınız için hekiminize danışın.
              </p>
              <div className="flex flex-wrap justify-between gap-2 text-[.8rem] text-ink-400">
                <span>© {new Date().getFullYear()} Raftabul. Tüm hakları saklıdır.</span>
                <span>Orijinal ürün garantisi · Onaylı satıcılar</span>
              </div>
            </div>
          </footer>
        </SessionProvider>
        {/* KVKK çerez onayı — only with a real GTM container (staging leaves it unset,
            so no banner and nothing to consent to). */}
        {process.env.NEXT_PUBLIC_GTM_ID && <CookieConsent />}
      </body>
    </html>
  );
}
