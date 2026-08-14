'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useState } from 'react';
import { SortSelect } from '@/components/SortSelect';
import type { ListingFacets, ProductSort } from '@/lib/api';
import { ui } from '@/lib/ui';

/**
 * The listing filter bar (ADR-080) — a price range and, where offered, a brand.
 *
 * URL-DRIVEN like the sort, so a filtered view stays shareable and crawlable and the
 * page stays a server component; this only reads the current URL and pushes a new one.
 * Brands come from the browse `meta.facets`, so the list is scoped to what actually
 * returns results — and the whole brand block hides until the backend supplies facets,
 * so it degrades cleanly before ADR-080 ships.
 */
export function ListingFilters({
  facets,
  sort,
  showBrand = true,
}: {
  facets: ListingFacets;
  sort?: ProductSort;
  showBrand?: boolean;
}) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const activeBrand = searchParams.get('brand') ?? '';
  const [min, setMin] = useState(searchParams.get('price_min') ?? '');
  const [max, setMax] = useState(searchParams.get('price_max') ?? '');

  function navigate(mutate: (params: URLSearchParams) => void) {
    const params = new URLSearchParams(searchParams.toString());
    mutate(params);
    params.delete('page');
    const query = params.toString();
    router.push(query === '' ? pathname : `${pathname}?${query}`);
  }

  const set = (params: URLSearchParams, key: string, value: string) =>
    value === '' ? params.delete(key) : params.set(key, value);

  const hasFilters =
    activeBrand !== '' || searchParams.get('price_min') !== null || searchParams.get('price_max') !== null;

  return (
    <div className="flex flex-wrap items-end gap-x-4 gap-y-3 rounded-2xl border border-ink-100 bg-white p-3.5 dark:border-ink-800 dark:bg-ink-900">
      <div className="flex items-end gap-2">
        <span className="text-[.8rem] font-bold text-ink-500">Fiyat</span>
        <input
          inputMode="numeric"
          aria-label="En düşük fiyat"
          placeholder={facets.price ? facets.price.min : 'En az'}
          value={min}
          onChange={(event) => setMin(event.target.value.replace(/[^\d.,]/g, ''))}
          className={`${ui.field} w-24`}
        />
        <span className="pb-2 text-ink-400">–</span>
        <input
          inputMode="numeric"
          aria-label="En yüksek fiyat"
          placeholder={facets.price ? facets.price.max : 'En çok'}
          value={max}
          onChange={(event) => setMax(event.target.value.replace(/[^\d.,]/g, ''))}
          className={`${ui.field} w-24`}
        />
        <button
          type="button"
          onClick={() =>
            navigate((params) => {
              set(params, 'price_min', min.trim());
              set(params, 'price_max', max.trim());
            })
          }
          className={ui.btnPrimarySm}
        >
          Uygula
        </button>
      </div>

      {showBrand && facets.brands.length > 0 && (
        <label className="flex items-center gap-2 text-[.8rem] font-bold text-ink-500">
          Marka
          <select
            aria-label="Marka"
            value={activeBrand}
            onChange={(event) => navigate((params) => set(params, 'brand', event.target.value))}
            className={`${ui.field} w-auto max-w-[220px]`}
          >
            <option value="">Tüm markalar</option>
            {facets.brands.map((brand) => (
              <option key={brand.slug} value={brand.slug}>
                {brand.name} ({brand.count})
              </option>
            ))}
          </select>
        </label>
      )}

      <div className="ml-auto flex items-center gap-3">
        {hasFilters && (
          <button
            type="button"
            onClick={() => {
              setMin('');
              setMax('');
              navigate((params) => {
                params.delete('brand');
                params.delete('price_min');
                params.delete('price_max');
              });
            }}
            className="text-sm font-bold text-brand-600 hover:underline"
          >
            Filtreleri temizle
          </button>
        )}

        {sort !== undefined && (
          <label className="flex items-center gap-2 text-[.8rem] font-bold text-ink-500">
            Sırala
            <SortSelect value={sort} />
          </label>
        )}
      </div>
    </div>
  );
}
