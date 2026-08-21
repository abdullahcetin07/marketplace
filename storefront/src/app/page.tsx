import type { Metadata } from 'next';
import { Fragment, Suspense } from 'react';
import Link from 'next/link';
import { fetchBrands, getBestSellers, getMostReviewed } from '@/lib/api';
import { CategoryStrip } from '@/components/CategoryStrip';
import { HeroSlider } from '@/components/HeroSlider';
import { ProductRail } from '@/components/ProductRail';
import { ProductRailSkeleton } from '@/components/ProductRailSkeleton';
import { PromoBlock } from '@/components/PromoBlock';
import { RecentlyViewed } from '@/components/RecentlyViewed';
import { RecommendedForYou } from '@/components/RecommendedForYou';
import { campaigns } from '@/lib/campaigns';
import { promoBlocks } from '@/lib/promo-banners';
import { jsonLd } from '@/lib/jsonld';
import { SITE_URL } from '@/lib/site';

// A keyword-rich home title of its own (absolute, so it skips the "%s — Raftabul"
// template) + a canonical, since this is the site's most-linked page.
export const metadata: Metadata = {
  title: { absolute: 'Raftabul — Dermokozmetik & Kişisel Bakım Pazaryeri' },
  description:
    'Onaylı satıcılardan orijinal dermokozmetik, vitamin, cilt bakım ve kişisel bakım ürünleri — en uygun fiyatlarla Raftabul’da. Güvenli ödeme, hızlı kargo.',
  alternates: { canonical: '/' },
};

// Homepage structured data: the brand as an Organization, and a WebSite with a
// SearchAction so Google can offer a sitelinks search box into the listing.
// The brand's public accounts, once they exist — `sameAs` is how Google ties this
// storefront to the same brand entity across the web. Empty for now, so it is
// omitted below rather than emitting an empty field; add URLs here to light it up.
const ORG_SAME_AS: string[] = [];

const organizationLd = {
  '@context': 'https://schema.org',
  '@type': 'Organization',
  '@id': `${SITE_URL}/#organization`,
  name: 'Raftabul',
  url: SITE_URL,
  description:
    'Onaylı eczane ve mağazaların orijinal sağlık, dermokozmetik, vitamin ve kişisel bakım ürünlerini buluşturan pazaryeri.',
  // The brand's only image asset today. Swap for a dedicated square logo (on white,
  // ≥112px) when there is one — Google can surface it beside the brand in results.
  logo: `${SITE_URL}/og-default.jpg`,
  ...(ORG_SAME_AS.length > 0 ? { sameAs: ORG_SAME_AS } : {}),
};

const websiteLd = {
  '@context': 'https://schema.org',
  '@type': 'WebSite',
  name: 'Raftabul',
  url: SITE_URL,
  potentialAction: {
    '@type': 'SearchAction',
    target: { '@type': 'EntryPoint', urlTemplate: `${SITE_URL}/urunler?q={search_term_string}` },
    'query-input': 'required name=search_term_string',
  },
};

/**
 * The curated, always-on category strips under the personalized ones, addressed by
 * EXACT slug (name-fragment matching was ambiguous — "güneş" caught child
 * categories, "vitamin" caught the narrow sub-category instead of the parent). Each
 * renders that category's first products and hides itself if the category is absent
 * or empty. Reorder / edit to change the homepage strips.
 */
const CATEGORY_STRIPS: { title: string; slug: string }[] = [
  { title: 'Güneş Kremleri', slug: 'gunes-kremleri' },
  { title: 'Besin Takviyeleri', slug: 'besin-takviyeleri' },
  { title: 'Cilt Bakımı', slug: 'cilt-bakimi' },
  { title: 'Makyaj', slug: 'makyaj' },
];

/**
 * RENDERED PER REQUEST, NOT BAKED AT BUILD TIME.
 *
 * Next would happily prerender this page during `next build` — and that is wrong
 * twice over for a marketplace. It would freeze prices and availability into
 * static HTML at deploy time, and it would make the build depend on a running
 * API, so a deploy could fail because the database was briefly busy.
 *
 * The DATA is still cached (the fetches opt into a 60-second window), so this
 * costs a render rather than a round trip. That is the right trade for a page
 * whose whole content is "what can be bought right now".
 */
export const dynamic = 'force-dynamic';

// The perks row is static merchandising, not live data — it lives here rather than
// behind a fetch. It leads with the loyalty programme: three of the four cards are ways
// to earn points, so "puan kazan" is the shelf's headline. (Points are earned for real
// via the Loyalty module; these cards just advertise it — they are not coupon codes.)
const coupons = [
  { amount: '0₺', title: 'Kargo Bedava', note: 'Tüm alışverişlerde', href: '/urunler' },
  { amount: 'PUAN', title: 'Üye Ol Kazan', note: 'Hoş geldin puanı', href: '/kayit' },
  { amount: 'PUAN', title: 'Yorum Yap Kazan', note: 'Onaylı her yorumda', href: '/hesap/siparislerim' },
  { amount: 'PUAN', title: 'Aldıkça Kazan', note: 'Her alışverişte', href: '/hesap/puanlarim' },
];

/**
 * The front page: brand shortcuts, the campaign hero slider, the personalized
 * strips ("Son baktıkların" / "Sana özel öneriler", client-side), and the curated
 * always-on category strips.
 *
 * A SERVER COMPONENT, so the marketplace's most linked page is fully rendered HTML
 * for a crawler rather than a spinner (§2.1) — the brand row and category strips
 * are the server-rendered content; the personalized strips hydrate on top.
 */
export default async function HomePage() {
  const [brands, bestSellers, mostReviewed] = await Promise.all([
    fetchBrands(),
    getBestSellers(),
    getMostReviewed(),
  ]);

  // Brand shortcuts, most-stocked first. Categories already live in the menu bar
  // above, so this row is brands now — a round logo where the brand has one,
  // its initials until an admin uploads it (Admin → Markalar).
  const topBrands = brands
    .filter((brand) => brand.product_count > 0)
    .sort((a, b) => b.product_count - a.product_count)
    .slice(0, 9);

  return (
    <div className="flex flex-col gap-9">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(organizationLd) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(websiteLd) }} />

      {/* The page's ONE h1. The hero is a campaign slider (or the fallback panel
          below) — neither is a reliable, stable heading for the crawler, so the
          home page's subject lives here, always rendered and sr-only so it names
          the page for search without competing with the hero's own visual copy. */}
      <h1 className="sr-only">
        Onaylı satıcılardan orijinal dermokozmetik, vitamin ve kişisel bakım ürünleri
      </h1>

      {/* brand shortcuts — round logos, linked by slug. On mobile it is a single-row
          horizontal carousel (full-bleed, snap, hidden scrollbar); from sm up it becomes
          the static grid. `shrink-0 basis-*` only bite in the flex row — grid ignores
          them — so one child markup serves both layouts. */}
      {topBrands.length > 0 && (
        <div className="-mx-4 flex snap-x snap-mandatory gap-2 overflow-x-auto px-4 pb-1 [-ms-overflow-style:none] [scrollbar-width:none] sm:mx-0 sm:grid sm:snap-none sm:grid-cols-5 sm:overflow-visible sm:px-0 sm:pb-0 lg:grid-cols-9 [&::-webkit-scrollbar]:hidden">
          {topBrands.map((brand) => (
            <Link
              key={brand.id}
              href={`/${brand.slug}`}
              className="flex shrink-0 basis-[22%] snap-start flex-col items-center gap-2.5 rounded-2xl px-1 py-3.5 transition hover:bg-brand-50 dark:hover:bg-ink-900 sm:basis-auto"
            >
              <span className="grid h-14 w-14 place-items-center overflow-hidden rounded-full bg-white shadow-sm ring-1 ring-ink-100 dark:ring-ink-800">
                {brand.logo ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={brand.logo} alt={brand.name} className="h-full w-full object-contain p-1.5" loading="lazy" />
                ) : (
                  <span className="text-sm font-extrabold text-brand-600">{brand.name.slice(0, 2).toLocaleUpperCase('tr')}</span>
                )}
              </span>
              <span className="line-clamp-2 text-center text-xs font-bold text-ink-600 dark:text-ink-300">{brand.name}</span>
            </Link>
          ))}
        </div>
      )}

      {/* hero — the campaign slider once banners are configured (src/lib/campaigns.ts),
          a plain marketplace panel until then */}
      {campaigns.length > 0 ? (
        <HeroSlider slides={campaigns} />
      ) : (
        <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-brand-500 to-brand-400 px-8 py-14 text-white">
          <span className="inline-flex w-max items-center gap-2 rounded-full bg-white/20 px-3 py-1.5 text-xs font-extrabold">
            Türkiye&apos;nin pazaryeri
          </span>
          {/* Visual hero heading, not the document h1 — the page's single h1 is the
              sr-only one above, so this stays a styled paragraph to avoid two h1s. */}
          <p className="mt-4 max-w-2xl text-3xl font-extrabold leading-tight tracking-tight text-balance sm:text-[2.7rem]">
            Binlerce ürün, güvenilir satıcılar
          </p>
          <p className="mt-3 max-w-xl text-[1.02rem] text-brand-50">
            Farklı mağazalardan ürünleri keşfet, karşılaştır ve güvenle satın al.
          </p>
          <Link
            href="/urunler"
            className="mt-6 inline-block rounded-xl bg-white px-6 py-3 font-extrabold text-brand-700 transition hover:bg-brand-50"
          >
            Alışverişe başla
          </Link>
        </section>
      )}

      {/* coupons */}
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {coupons.map((c) => (
          <Link
            key={c.title}
            href={c.href}
            className="flex items-center gap-3.5 rounded-2xl border border-dashed border-ink-300 bg-white p-4 transition hover:border-brand-400 hover:shadow-sm dark:border-ink-700 dark:bg-ink-900"
          >
            <div className="whitespace-nowrap text-2xl font-extrabold tracking-tight text-brand-600">{c.amount}</div>
            <div className="border-l border-dashed border-ink-300 pl-3.5 dark:border-ink-700">
              <div className="text-sm font-bold">{c.title}</div>
              <div className="text-xs text-ink-500">{c.note}</div>
            </div>
          </Link>
        ))}
      </div>

      {/* "Son baktıkların" (≥3 views); hidden otherwise */}
      <RecentlyViewed />

      {/* "Sana özel öneriler" — same-category picks; hides itself without view history */}
      <RecommendedForYou />

      {/* ranking strips — hidden until their backend endpoints have data, then auto-appear */}
      <ProductRail title="Çok Satanlar" items={bestSellers} />
      <ProductRail title="En Çok Değerlendirilenler" items={mostReviewed} />

      {/* curated category strips (exact slugs; each hides if empty), each followed by
          its promo banner block. The strip's browse is streamed behind Suspense so it
          never blocks the page shell. */}
      {CATEGORY_STRIPS.map((strip) => (
        <Fragment key={strip.slug}>
          <Suspense fallback={<ProductRailSkeleton />}>
            <CategoryStrip title={strip.title} slug={strip.slug} href={`/${strip.slug}`} />
          </Suspense>
          <PromoBlock block={promoBlocks[strip.slug]} />
        </Fragment>
      ))}
    </div>
  );
}
