'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { SORTS, SortSelect } from '@/components/SortSelect';
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
 *
 * TWO SKINS, ONE STATE. From `sm` up it is the inline bar (each control applies on
 * change). On mobile it collapses to a single "Sırala ve Filtrele" button that opens a
 * bottom sheet, where selections are DRAFTED and applied together on one tap — so a
 * shopper is never bounced out of the sheet mid-choice by an eager navigation.
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

  // Mobile bottom-sheet state + its drafts (brand/sort are applied live on desktop).
  const [open, setOpen] = useState(false);
  const [draftBrand, setDraftBrand] = useState(activeBrand);
  const [draftSort, setDraftSort] = useState<ProductSort | undefined>(sort);

  // Lock the page behind the sheet while it is open.
  useEffect(() => {
    if (!open) return;
    const previous = document.body.style.overflow;
    document.body.style.overflow = 'hidden';

    return () => {
      document.body.style.overflow = previous;
    };
  }, [open]);

  function navigate(mutate: (params: URLSearchParams) => void) {
    const params = new URLSearchParams(searchParams.toString());
    mutate(params);
    params.delete('page');
    const query = params.toString();
    setOpen(false);
    router.push(query === '' ? pathname : `${pathname}?${query}`);
  }

  const set = (params: URLSearchParams, key: string, value: string) =>
    value === '' ? params.delete(key) : params.set(key, value);

  const hasFilters =
    activeBrand !== '' || searchParams.get('price_min') !== null || searchParams.get('price_max') !== null;

  // How many filter facets are active — shown as a badge on the mobile trigger.
  const activeCount =
    (activeBrand !== '' ? 1 : 0) +
    (searchParams.get('price_min') !== null || searchParams.get('price_max') !== null ? 1 : 0);

  // Open the sheet with its drafts synced to the current URL.
  function openSheet() {
    setDraftBrand(activeBrand);
    setDraftSort(sort);
    setMin(searchParams.get('price_min') ?? '');
    setMax(searchParams.get('price_max') ?? '');
    setOpen(true);
  }

  // Apply every draft at once (mobile sheet).
  function applyDrafts() {
    navigate((params) => {
      if (draftSort === undefined || draftSort === 'newest') params.delete('sort');
      else params.set('sort', draftSort);
      set(params, 'brand', draftBrand);
      set(params, 'price_min', min.trim());
      set(params, 'price_max', max.trim());
    });
  }

  function clearAll() {
    setMin('');
    setMax('');
    setDraftBrand('');
    navigate((params) => {
      params.delete('brand');
      params.delete('price_min');
      params.delete('price_max');
    });
  }

  return (
    <>
      {/* ---------- Desktop / tablet: the inline bar ---------- */}
      <div className="hidden flex-wrap items-end gap-x-4 gap-y-3 rounded-2xl border border-ink-100 bg-white p-3.5 dark:border-ink-800 dark:bg-ink-900 sm:flex">
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
              onClick={clearAll}
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

      {/* ---------- Mobile: trigger button ---------- */}
      <button
        type="button"
        onClick={openSheet}
        className={`${ui.card} flex w-full items-center justify-center gap-2 px-4 py-2.5 text-sm font-bold text-ink-600 dark:text-ink-200 sm:hidden`}
      >
        <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
          <path d="M4 6h16M7 12h10M10 18h4" />
        </svg>
        Sırala ve Filtrele
        {activeCount > 0 && (
          <span className="grid h-5 min-w-5 place-items-center rounded-full bg-brand-500 px-1 text-xs font-extrabold text-white">
            {activeCount}
          </span>
        )}
      </button>

      {/* ---------- Mobile: bottom sheet ---------- */}
      {open && (
        <div className="fixed inset-0 z-[70] sm:hidden" role="dialog" aria-modal="true" aria-label="Sırala ve Filtrele">
          <button
            type="button"
            aria-label="Kapat"
            onClick={() => setOpen(false)}
            className="absolute inset-0 bg-ink-950/40"
          />
          <div className="absolute inset-x-0 bottom-0 flex max-h-[85vh] flex-col rounded-t-2xl bg-white dark:bg-ink-900">
            <div className="shrink-0 px-5 pt-3">
              <div className="mx-auto mb-3 h-1 w-10 rounded-full bg-ink-200 dark:bg-ink-700" />
              <div className="flex items-center justify-between">
                <h3 className={ui.h2}>Sırala ve Filtrele</h3>
                <button type="button" onClick={() => setOpen(false)} aria-label="Kapat" className="grid h-8 w-8 place-items-center rounded-full text-ink-500 hover:bg-ink-100 dark:hover:bg-ink-800">
                  <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round">
                    <path d="M6 6l12 12M18 6 6 18" />
                  </svg>
                </button>
              </div>
            </div>

            <div className="flex-1 overflow-y-auto px-5 py-4">
              {sort !== undefined && (
                <fieldset className="mb-6">
                  <legend className="mb-2 text-[.8rem] font-bold text-ink-500">Sırala</legend>
                  <div className="flex flex-col gap-2">
                    {SORTS.map((option) => {
                      const active = draftSort === option.value;

                      return (
                        <button
                          key={option.value}
                          type="button"
                          onClick={() => setDraftSort(option.value)}
                          className={`flex items-center justify-between rounded-xl border-2 px-3.5 py-2.5 text-sm font-bold transition ${
                            active
                              ? 'border-brand-500 text-brand-600'
                              : 'border-ink-200 text-ink-600 dark:border-ink-700 dark:text-ink-200'
                          }`}
                        >
                          {option.label}
                          {active && (
                            <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2.4} strokeLinecap="round" strokeLinejoin="round">
                              <path d="m5 13 4 4L19 7" />
                            </svg>
                          )}
                        </button>
                      );
                    })}
                  </div>
                </fieldset>
              )}

              <div className="mb-6">
                <span className="mb-2 block text-[.8rem] font-bold text-ink-500">Fiyat aralığı</span>
                <div className="flex items-center gap-2">
                  <input
                    inputMode="numeric"
                    aria-label="En düşük fiyat"
                    placeholder={facets.price ? facets.price.min : 'En az'}
                    value={min}
                    onChange={(event) => setMin(event.target.value.replace(/[^\d.,]/g, ''))}
                    className={`${ui.field} flex-1`}
                  />
                  <span className="text-ink-400">–</span>
                  <input
                    inputMode="numeric"
                    aria-label="En yüksek fiyat"
                    placeholder={facets.price ? facets.price.max : 'En çok'}
                    value={max}
                    onChange={(event) => setMax(event.target.value.replace(/[^\d.,]/g, ''))}
                    className={`${ui.field} flex-1`}
                  />
                </div>
              </div>

              {showBrand && facets.brands.length > 0 && (
                <div className="mb-2">
                  <span className="mb-2 block text-[.8rem] font-bold text-ink-500">Marka</span>
                  <select
                    aria-label="Marka"
                    value={draftBrand}
                    onChange={(event) => setDraftBrand(event.target.value)}
                    className={`${ui.field} w-full`}
                  >
                    <option value="">Tüm markalar</option>
                    {facets.brands.map((brand) => (
                      <option key={brand.slug} value={brand.slug}>
                        {brand.name} ({brand.count})
                      </option>
                    ))}
                  </select>
                </div>
              )}
            </div>

            <div className="flex shrink-0 gap-3 border-t border-ink-100 px-5 py-4 dark:border-ink-800">
              {hasFilters && (
                <button type="button" onClick={clearAll} className={`${ui.btnGhost} flex-1 py-2.5`}>
                  Temizle
                </button>
              )}
              <button type="button" onClick={applyDrafts} className={`${ui.btnPrimary} flex-1 py-2.5`}>
                Uygula
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
