import type { Metadata } from 'next';
import Link from 'next/link';
import { browseProducts, type ProductSort } from '@/lib/api';
import { ListingFilters } from '@/components/ListingFilters';
import { ProductGrid } from '@/components/ProductGrid';
import { ui } from '@/lib/ui';

/**
 * The listing is ONE indexable page — clean `/urunler` — plus a cloud of faceted
 * variants (`?q=`, `?sort=`, `?page=`, `?category=`, `?brand=`) that are either
 * near-duplicates of it or of a category/brand hub. Index the clean page with a
 * self-canonical; tell the crawler to skip the parametrized variants but still
 * walk through to the products (`noindex, follow`), so search/sort/filter URLs
 * don't bloat the index with thin duplicates.
 */
export async function generateMetadata({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}): Promise<Metadata> {
  const params = await searchParams;
  const q = single(params.q);
  const page = Math.max(1, Number(single(params.page) ?? 1) || 1);

  // A search/sort/filter variant is a thin near-duplicate — noindex,follow. But a
  // PURE pagination page (`?page=N`, nothing else) is a distinct slice of the
  // catalogue, so it stays indexable and self-canonicalizes to `?page=N` rather
  // than collapsing to page 1 (which would leave page 2+ under-indexed).
  const filtered = Boolean(
    q ||
      single(params.sort) ||
      single(params.category) ||
      single(params.brand) ||
      single(params.price_min) ||
      single(params.price_max),
  );

  const title = q ? `“${q}” için sonuçlar` : 'Tüm ürünler';

  if (filtered) return { title, robots: { index: false, follow: true } };
  if (page > 1) return { title, alternates: { canonical: `/urunler?page=${page}` } };

  return { title, alternates: { canonical: '/urunler' } };
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

  // A transient failure of the internal catalogue read must not white-screen the
  // whole page (the raw Next 500). browseProducts already retries once; if it still
  // fails we render a calm "try again" panel with a 200 rather than throwing, so a
  // brief backend blip costs a reload, not a broken page.
  let result: Awaited<ReturnType<typeof browseProducts>> | null = null;
  try {
    result = await browseProducts({
      q: q === '' ? undefined : q,
      category: single(params.category),
      brand: single(params.brand),
      priceMin: single(params.price_min),
      priceMax: single(params.price_max),
      sort,
      page,
      perPage: 24,
    });
  } catch {
    result = null;
  }

  if (result === null) {
    return (
      <div className={`flex flex-col items-center gap-3 py-16 text-center ${ui.card}`}>
        <h1 className="text-lg font-extrabold tracking-tight">Ürünler şu an yüklenemedi</h1>
        <p className="max-w-sm text-sm text-ink-500">
          Geçici bir yoğunluk olabilir. Lütfen birkaç saniye sonra tekrar deneyin.
        </p>
        <Link href="/urunler" className={`${ui.btnPrimarySm} mt-1`}>
          Tekrar dene
        </Link>
      </div>
    );
  }

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className={ui.h1}>{q === '' ? 'Tüm ürünler' : `“${q}” için sonuçlar`}</h1>
          <p className="mt-1 text-sm text-ink-500">
            <span className="font-bold text-ink-700 dark:text-ink-200">{result.total}</span> ürün
          </p>
        </div>

        <form method="get" className="flex flex-wrap items-center gap-2">
          <input
            type="search"
            name="q"
            defaultValue={q}
            placeholder="Ürün ara"
            aria-label="Ürün ara"
            className={`${ui.field} w-auto`}
          />

          {/* The category, brand, sort and price filters ride through a search change
              rather than being reset by it — losing a filter because you searched
              is the classic listing annoyance. Sorting itself lives in the filter
              bar below, next to price and brand. */}
          {passthrough(params.category, 'category')}
          {passthrough(params.brand, 'brand')}
          {passthrough(params.sort, 'sort')}
          {passthrough(params.price_min, 'price_min')}
          {passthrough(params.price_max, 'price_max')}

          <button type="submit" className={ui.btnPrimarySm}>
            Uygula
          </button>
        </form>
      </div>

      <ListingFilters facets={result.facets} sort={sort} />

      {result.items.length === 0 ? (
        <div className={`flex flex-col items-center gap-3 py-16 text-center ${ui.card}`}>
          <h2 className="text-lg font-extrabold tracking-tight">Sonuç bulunamadı</h2>
          <p className="max-w-sm text-sm text-ink-500">
            Aradığınız kriterlere uyan ürün yok. Farklı bir arama veya filtre deneyin.
          </p>
          <Link href="/urunler" className={`${ui.btnGhost} mt-1 px-4 py-2 text-sm`}>
            Tüm ürünler
          </Link>
        </div>
      ) : (
        <ProductGrid products={result.items} />
      )}

      {result.lastPage > 1 && (
        <nav className="flex items-center justify-center gap-3 pt-4 text-sm">
          {page > 1 && (
            <Link
              href={pageUrl(params, page - 1)}
              className="rounded-xl border-2 border-ink-200 px-4 py-2 font-bold transition hover:border-brand-400 dark:border-ink-700"
            >
              ← Önceki
            </Link>
          )}

          <span className="font-semibold text-ink-500">
            {page} / {result.lastPage}
          </span>

          {page < result.lastPage && (
            <Link
              href={pageUrl(params, page + 1)}
              className="rounded-xl border-2 border-ink-200 px-4 py-2 font-bold transition hover:border-brand-400 dark:border-ink-700"
            >
              Sonraki →
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

  for (const key of ['q', 'category', 'brand', 'sort', 'price_min', 'price_max'] as const) {
    const value = single(params[key]);
    if (value !== undefined && value !== '') query.set(key, value);
  }

  if (page > 1) query.set('page', String(page));

  return query.toString() === '' ? '/urunler' : `/urunler?${query.toString()}`;
}
