import type { Metadata } from 'next';
import Link from 'next/link';
import { browseProducts, type ProductSort } from '@/lib/api';
import { ProductGrid } from '@/components/ProductGrid';

export const metadata: Metadata = {
  title: 'Tüm ürünler',
};

const SORTS: { value: ProductSort; label: string }[] = [
  { value: 'newest', label: 'En yeni' },
  { value: 'price_asc', label: 'En düşük fiyat' },
  { value: 'price_desc', label: 'En yüksek fiyat' },
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

/**
 * Search and listing (§2.2).
 *
 * THE FILTERS LIVE IN THE URL, not in component state, and that is the decision
 * worth naming: a search result a shopper can copy, share, bookmark and reload is
 * worth more than one that animates. It also means this stays a server component
 * — every filter change is a new request the crawler can follow too.
 *
 * THE SORT IS VALIDATED HERE AS WELL AS ON THE SERVER. Not defensive duplication:
 * the API falls back to its default for an unknown value, and this makes the
 * SELECT show what the API actually did rather than echoing what the URL claimed.
 */
export default async function ProductsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;

  const q = single(params.q) ?? '';
  const sort = asSort(single(params.sort));
  const page = Math.max(1, Number(single(params.page) ?? 1) || 1);

  const result = await browseProducts({
    q: q === '' ? undefined : q,
    category: single(params.category),
    brand: single(params.brand),
    sort,
    page,
    perPage: 24,
  });

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold">{q === '' ? 'Tüm ürünler' : `"${q}" için sonuçlar`}</h1>
          <p className="mt-1 text-sm text-ink-500">{result.total} ürün</p>
        </div>

        <form method="get" className="flex flex-wrap items-center gap-2">
          <input
            type="search"
            name="q"
            defaultValue={q}
            placeholder="Ürün ara"
            aria-label="Ürün ara"
            className="rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-900"
          />

          <select
            name="sort"
            defaultValue={sort}
            aria-label="Sırala"
            className="rounded-lg border border-ink-300 px-3 py-2 text-sm dark:border-ink-700 dark:bg-ink-900"
          >
            {SORTS.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>

          {/* The category and brand filters ride through a sort or search change
              rather than being reset by it — losing a filter because you sorted
              is the classic listing annoyance. */}
          {passthrough(params.category, 'category')}
          {passthrough(params.brand, 'brand')}

          <button
            type="submit"
            className="rounded-lg bg-brand-500 px-4 py-2 text-sm font-medium text-white transition hover:bg-brand-600"
          >
            Uygula
          </button>
        </form>
      </div>

      <ProductGrid products={result.items} />

      {result.lastPage > 1 && (
        <nav className="flex items-center justify-center gap-3 pt-4 text-sm">
          {page > 1 && (
            <Link
              href={pageUrl(params, page - 1)}
              className="rounded-lg border border-ink-300 px-4 py-2 hover:border-brand-400 dark:border-ink-700"
            >
              Önceki
            </Link>
          )}

          <span className="text-ink-500">
            {page} / {result.lastPage}
          </span>

          {page < result.lastPage && (
            <Link
              href={pageUrl(params, page + 1)}
              className="rounded-lg border border-ink-300 px-4 py-2 hover:border-brand-400 dark:border-ink-700"
            >
              Sonraki
            </Link>
          )}
        </nav>
      )}
    </div>
  );
}

/** A query param may arrive repeated; the last one wins, as a browser would. */
function single(value: string | string[] | undefined): string | undefined {
  if (Array.isArray(value)) return value[value.length - 1];

  return value;
}

function asSort(value: string | undefined): ProductSort {
  return value === 'price_asc' || value === 'price_desc' || value === 'newest' ? value : 'newest';
}

function passthrough(value: string | string[] | undefined, name: string) {
  const only = single(value);

  return only === undefined ? null : <input type="hidden" name={name} value={only} />;
}

function pageUrl(params: Record<string, string | string[] | undefined>, page: number): string {
  const query = new URLSearchParams();

  for (const key of ['q', 'category', 'brand', 'sort'] as const) {
    const value = single(params[key]);
    if (value !== undefined && value !== '') query.set(key, value);
  }

  if (page > 1) query.set('page', String(page));

  return query.toString() === '' ? '/urunler' : `/urunler?${query.toString()}`;
}
