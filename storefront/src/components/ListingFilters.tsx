'use client';

import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { SORTS, SortSelect } from '@/components/SortSelect';
import type { ListingFacets, ProductSort } from '@/lib/api';
import { ui } from '@/lib/ui';

/** A sub-category shown in the mobile "Kategoriler" sheet (desktop keeps the pill row). */
export type ListingCategory = { id: string; name: string; slug: string; product_count: number };

/** Which section the mobile bottom sheet is showing. */
type Section = 'sort' | 'filter' | 'categories';

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
 * change). On mobile it becomes a row of tap-to-open buttons — **Sırala**, **Filtrele**
 * and, on a category page, **Kategoriler** — each opening the same bottom sheet on its
 * own section. Sort and category tap through immediately (a single choice); the filter
 * section DRAFTS price + brand and applies them together, so a shopper is never bounced
 * out mid-choice by an eager navigation.
 */
export function ListingFilters({
  facets,
  sort,
  showBrand = true,
  categories = [],
}: {
  facets: ListingFacets;
  sort?: ProductSort;
  showBrand?: boolean;
  /** Sub-categories to drill into — surfaced as the mobile "Kategoriler" sheet. */
  categories?: ListingCategory[];
}) {
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const activeBrand = searchParams.get('brand') ?? '';
  const [min, setMin] = useState(searchParams.get('price_min') ?? '');
  const [max, setMax] = useState(searchParams.get('price_max') ?? '');

  // Mobile bottom-sheet state + its drafts (brand/sort are applied live on desktop).
  const [open, setOpen] = useState(false);
  const [section, setSection] = useState<Section>('filter');
  const [draftBrand, setDraftBrand] = useState(activeBrand);

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

  // Open the sheet on a given section, with its drafts synced to the current URL.
  function openSheet(next: Section) {
    setSection(next);
    setDraftBrand(activeBrand);
    setMin(searchParams.get('price_min') ?? '');
    setMax(searchParams.get('price_max') ?? '');
    setOpen(true);
  }

  // Sort is a single choice — apply it on tap and close (no draft step).
  function selectSort(value: ProductSort) {
    navigate((params) => (value === 'newest' ? params.delete('sort') : params.set('sort', value)));
  }

  // Apply the filter drafts together (mobile sheet). Sort rides along untouched — it
  // is applied in its own section, and `navigate` starts from the current URL so an
  // existing `sort` param is preserved here.
  function applyDrafts() {
    navigate((params) => {
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

      {/* ---------- Mobile: trigger row (Sırala · Filtrele · Kategoriler) ---------- */}
      {(() => {
        const hasSort = sort !== undefined;
        const hasCategories = categories.length > 0;
        const count = (hasSort ? 1 : 0) + 1 + (hasCategories ? 1 : 0);
        const triggerCls = `${ui.card} flex items-center justify-center gap-1.5 px-2 py-2.5 text-sm font-bold text-ink-600 dark:text-ink-200`;

        return (
          <div className="grid gap-2 sm:hidden" style={{ gridTemplateColumns: `repeat(${count}, minmax(0, 1fr))` }}>
            {hasSort && (
              <button type="button" onClick={() => openSheet('sort')} className={triggerCls}>
                <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                  <path d="M7 4v16m0 0-3-3m3 3 3-3M17 20V4m0 0-3 3m3-3 3 3" />
                </svg>
                Sırala
              </button>
            )}
            <button type="button" onClick={() => openSheet('filter')} className={triggerCls}>
              <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                <path d="M4 6h16M7 12h10M10 18h4" />
              </svg>
              Filtrele
              {activeCount > 0 && (
                <span className="grid h-5 min-w-5 place-items-center rounded-full bg-brand-500 px-1 text-xs font-extrabold text-white">
                  {activeCount}
                </span>
              )}
            </button>
            {hasCategories && (
              <button type="button" onClick={() => openSheet('categories')} className={triggerCls}>
                <svg viewBox="0 0 24 24" className="h-[18px] w-[18px]" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
                  <rect x="3" y="3" width="7" height="7" rx="1.5" />
                  <rect x="14" y="3" width="7" height="7" rx="1.5" />
                  <rect x="3" y="14" width="7" height="7" rx="1.5" />
                  <rect x="14" y="14" width="7" height="7" rx="1.5" />
                </svg>
                Kategoriler
              </button>
            )}
          </div>
        );
      })()}

      {/* ---------- Mobile: bottom sheet (section-aware) ---------- */}
      {open && (
        <div
          className="fixed inset-0 z-[70] sm:hidden"
          role="dialog"
          aria-modal="true"
          aria-label={section === 'sort' ? 'Sırala' : section === 'categories' ? 'Kategoriler' : 'Filtrele'}
        >
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
                <h3 className={ui.h2}>
                  {section === 'sort' ? 'Sırala' : section === 'categories' ? 'Kategoriler' : 'Filtrele'}
                </h3>
                <button type="button" onClick={() => setOpen(false)} aria-label="Kapat" className="grid h-8 w-8 place-items-center rounded-full text-ink-500 hover:bg-ink-100 dark:hover:bg-ink-800">
                  <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round">
                    <path d="M6 6l12 12M18 6 6 18" />
                  </svg>
                </button>
              </div>
            </div>

            <div className="flex-1 overflow-y-auto px-5 py-4">
              {/* --- Sırala: single choice, applies on tap --- */}
              {section === 'sort' && sort !== undefined && (
                <div className="flex flex-col gap-2">
                  {SORTS.map((option) => {
                    const active = sort === option.value;

                    return (
                      <button
                        key={option.value}
                        type="button"
                        onClick={() => selectSort(option.value)}
                        className={`flex items-center justify-between rounded-xl border-2 px-3.5 py-3 text-sm font-bold transition ${
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
              )}

              {/* --- Kategoriler: tap through to the sub-category --- */}
              {section === 'categories' && (
                <div className="flex flex-col gap-2">
                  {categories.map((child) => (
                    <Link
                      key={child.id}
                      href={`/${child.slug}`}
                      onClick={() => setOpen(false)}
                      className="flex items-center justify-between rounded-xl border-2 border-ink-200 px-3.5 py-3 text-sm font-bold text-ink-600 transition hover:border-brand-400 hover:text-brand-600 dark:border-ink-700 dark:text-ink-200"
                    >
                      {child.name}
                      <span className="text-xs font-semibold text-ink-400">{child.product_count}</span>
                    </Link>
                  ))}
                </div>
              )}

              {/* --- Filtrele: draft price + brand, apply together --- */}
              {section === 'filter' && (
                <>
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
                </>
              )}
            </div>

            {/* Footer only for the filter section — sort/categories apply on tap. */}
            {section === 'filter' && (
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
            )}
          </div>
        </div>
      )}
    </>
  );
}
