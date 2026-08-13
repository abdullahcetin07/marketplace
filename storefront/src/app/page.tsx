import type { Metadata } from 'next';
import Link from 'next/link';
import { fetchBrands, fetchCategoryTree, type CategoryNode } from '@/lib/api';
import { CategoryStrip } from '@/components/CategoryStrip';
import { HeroSlider } from '@/components/HeroSlider';
import { RecentlyViewed } from '@/components/RecentlyViewed';
import { RecommendedForYou } from '@/components/RecommendedForYou';
import { campaigns } from '@/lib/campaigns';
import { SITE_URL } from '@/lib/site';

// A keyword-rich home title of its own (absolute, so it skips the "%s — Raftabul"
// template) + a canonical, since this is the site's most-linked page.
export const metadata: Metadata = {
  title: { absolute: 'Raftabul — Dermokozmetik, Vitamin & Kişisel Bakım Pazaryeri' },
  description:
    'Onaylı satıcılardan orijinal dermokozmetik, vitamin, cilt bakım ve kişisel bakım ürünleri — en uygun fiyatlarla Raftabul’da. Güvenli ödeme, hızlı kargo.',
  alternates: { canonical: '/' },
};

// Homepage structured data: the brand as an Organization, and a WebSite with a
// SearchAction so Google can offer a sitelinks search box into the listing.
const organizationLd = {
  '@context': 'https://schema.org',
  '@type': 'Organization',
  name: 'Raftabul',
  url: SITE_URL,
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
 * The curated, always-on category strips under the personalized ones. Resolved
 * against the REAL category tree by a name fragment, so a strip finds its category
 * whatever its exact name/slug is — and simply doesn't render if that category is
 * absent or empty. Reorder or extend this list to change the homepage strips.
 */
const CATEGORY_STRIPS: { title: string; match: string }[] = [
  { title: 'Güneş Kremleri', match: 'güneş' },
  { title: 'Vitaminler', match: 'vitamin' },
  { title: 'Cilt Bakımı', match: 'cilt bak' },
  { title: 'Makyaj', match: 'makyaj' },
];

function flattenCategories(nodes: CategoryNode[]): CategoryNode[] {
  return nodes.flatMap((node) => [node, ...flattenCategories(node.children)]);
}

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

// Promotional coupons are static — they are merchandising, not live data — so they
// live here rather than behind a fetch. The brand shortcuts, by contrast, are the real
// brand list, fetched below and linked by slug; categories live in the menu bar above.
const coupons = [
  { amount: '50₺', title: '500 TL üzeri', note: 'Kod: SAGLIK50' },
  { amount: '%15', title: 'Dermokozmetik', note: 'Sepette otomatik' },
  { amount: '0₺', title: 'Kargo bedava', note: '200 TL üzeri' },
  { amount: '%10', title: 'İlk siparişe', note: 'Kod: MERHABA' },
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
  const [brands, tree] = await Promise.all([fetchBrands(), fetchCategoryTree()]);

  // Brand shortcuts, most-stocked first. Categories already live in the menu bar
  // above, so this row is brands now — a round logo where the brand has one,
  // its initials until an admin uploads it (Admin → Markalar).
  const topBrands = brands
    .filter((brand) => brand.product_count > 0)
    .sort((a, b) => b.product_count - a.product_count)
    .slice(0, 9);

  // Curated category strips, resolved to real categories by name (unmatched/empty
  // ones simply don't render).
  const flatCats = flattenCategories(tree);
  const strips = CATEGORY_STRIPS.map((strip) => {
    const category = flatCats.find(
      (node) => node.product_count > 0 && node.name.toLocaleLowerCase('tr').includes(strip.match),
    );
    return category ? { title: strip.title, slug: category.slug } : null;
  }).filter((strip): strip is { title: string; slug: string } => strip !== null);

  return (
    <div className="flex flex-col gap-9">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(organizationLd) }} />
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(websiteLd) }} />

      {/* The page's ONE h1. The hero is a campaign slider (or the fallback panel
          below) — neither is a reliable, stable heading for the crawler, so the
          home page's subject lives here, always rendered and sr-only so it names
          the page for search without competing with the hero's own visual copy. */}
      <h1 className="sr-only">
        Onaylı satıcılardan orijinal dermokozmetik, vitamin ve kişisel bakım ürünleri
      </h1>

      {/* brand shortcuts — round logos, linked by slug */}
      {topBrands.length > 0 && (
        <div className="grid grid-cols-3 gap-2 sm:grid-cols-5 lg:grid-cols-9">
          {topBrands.map((brand) => (
            <Link
              key={brand.id}
              href={`/${brand.slug}`}
              className="flex flex-col items-center gap-2.5 rounded-2xl px-1 py-3.5 transition hover:bg-brand-50 dark:hover:bg-ink-900"
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
          <div
            key={c.title}
            className="flex items-center gap-3.5 rounded-2xl border border-dashed border-ink-300 bg-white p-4 dark:border-ink-700 dark:bg-ink-900"
          >
            <div className="whitespace-nowrap text-2xl font-extrabold tracking-tight text-brand-600">{c.amount}</div>
            <div className="border-l border-dashed border-ink-300 pl-3.5 dark:border-ink-700">
              <div className="text-sm font-bold">{c.title}</div>
              <div className="text-xs text-ink-500">{c.note}</div>
            </div>
          </div>
        ))}
      </div>

      {/* "Son baktıkların" (≥3 views); hidden otherwise */}
      <RecentlyViewed />

      {/* "Sana özel öneriler" — same-category picks; hides itself without view history */}
      <RecommendedForYou />

      {/* curated, always-on category strips (real categories, resolved by name) */}
      {strips.map((strip) => (
        <CategoryStrip key={strip.slug} title={strip.title} slug={strip.slug} href={`/${strip.slug}`} />
      ))}
    </div>
  );
}
