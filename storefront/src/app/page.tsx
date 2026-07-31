import Link from 'next/link';
import { browseProducts } from '@/lib/api';
import { ProductGrid } from '@/components/ProductGrid';

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
  const page = await browseProducts({ perPage: 12 });

  return (
    <div className="flex flex-col gap-10">
      <section className="rounded-2xl bg-gradient-to-br from-brand-500 to-brand-700 px-8 py-14 text-white">
        <h1 className="max-w-2xl text-3xl font-bold leading-tight sm:text-4xl">
          Binlerce satıcı, tek pazar yeri.
        </h1>
        <p className="mt-3 max-w-xl text-brand-50">
          Aradığınızı en uygun fiyatla sunan satıcıyı sizin için öne çıkarıyoruz.
        </p>
        <Link
          href="/urunler"
          className="mt-6 inline-block rounded-lg bg-white px-5 py-2.5 font-medium text-brand-700 transition hover:bg-brand-50"
        >
          Ürünleri keşfet
        </Link>
      </section>

      <section className="flex flex-col gap-5">
        <div className="flex items-baseline justify-between">
          <h2 className="text-xl font-bold">Yeni eklenenler</h2>
          <Link href="/urunler" className="text-sm text-brand-600 hover:underline">
            tümünü gör
          </Link>
        </div>

        <ProductGrid products={page.items} />
      </section>
    </div>
  );
}
