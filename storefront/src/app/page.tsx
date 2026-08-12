import Link from 'next/link';
import { browseProducts, fetchBrands } from '@/lib/api';
import { HeroSlider } from '@/components/HeroSlider';
import { ProductGrid } from '@/components/ProductGrid';
import { RecentlyViewed } from '@/components/RecentlyViewed';
import { RecommendedForYou } from '@/components/RecommendedForYou';
import { campaigns } from '@/lib/campaigns';

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
 * The front page — newest sellable products (§2.2).
 *
 * A SERVER COMPONENT, so the marketplace's most linked page is fully rendered
 * HTML for a crawler rather than a spinner (§2.1).
 *
 * "Newest" is the API's default sort, and it is the honest thing to show a
 * visitor with no query: campaigns and curation need an editorial surface nobody
 * has built yet, and a hard-coded "featured" list would be a lie about how it was
 * chosen.
 */
export default async function HomePage() {
  const [page, brands] = await Promise.all([browseProducts({ perPage: 12 }), fetchBrands()]);
  // Brand shortcuts, most-stocked first. Categories already live in the menu bar
  // above, so this row is brands now — a round logo where the brand has one,
  // its initials until an admin uploads it (Admin → Markalar).
  const topBrands = brands
    .filter((brand) => brand.product_count > 0)
    .sort((a, b) => b.product_count - a.product_count)
    .slice(0, 9);

  return (
    <div className="flex flex-col gap-9">
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
          <h1 className="mt-4 max-w-2xl text-3xl font-extrabold leading-tight tracking-tight text-balance sm:text-[2.7rem]">
            Binlerce ürün, güvenilir satıcılar
          </h1>
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

      {/* "Son baktıkların" for a returning visitor; the newest grid (below) is the
          server-rendered fallback shown to first-time visitors and crawlers */}
      <RecentlyViewed>
        <section className="flex flex-col gap-5">
          <div className="flex items-baseline justify-between">
            <h2 className="text-xl font-extrabold tracking-tight">Yeni eklenenler</h2>
            <Link href="/urunler" className="text-sm font-bold text-brand-600 hover:underline">
              tümünü gör →
            </Link>
          </div>

          <ProductGrid products={page.items} />
        </section>
      </RecentlyViewed>

      {/* "Sana özel öneriler" — same-category picks; hides itself without view history */}
      <RecommendedForYou />
    </div>
  );
}
