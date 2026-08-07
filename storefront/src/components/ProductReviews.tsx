'use client';

import { useCallback, useEffect, useState } from 'react';
import { Stars } from '@/components/Stars';
import {
  getProductReviews,
  type ProductReview,
  type ProductReviewsPage,
  type ReviewFilters,
} from '@/lib/api';

/**
 * The reviews section of a product page (Reviews, ADR-069).
 *
 * SERVER-SEEDED, CLIENT-INTERACTIVE. The first page is fetched on the server (SEO
 * + instant paint) and handed in as `initial`; the seller filter, the "sadece
 * resimli" toggle, the star filter and "daha fazla" all re-fetch in the browser.
 *
 * IT NEVER DOES ARITHMETIC ON A RATING STRING. The average arrives as `"4.3"` and
 * is printed as-is; only the distribution bars compute widths, and those are on
 * integer counts.
 */
export function ProductReviews({ productId, initial }: { productId: string; initial: ProductReviewsPage }) {
  const [filters, setFilters] = useState<ReviewFilters>({});
  const [page, setPage] = useState<ProductReviewsPage>(initial);
  const [items, setItems] = useState<ProductReview[]>(initial.reviews);
  const [loading, setLoading] = useState(false);
  // The summary is a property of the product, not of the filtered view — keep the
  // unfiltered one from the initial load so the header and the seller list are stable.
  const summary = initial.summary;

  const load = useCallback(
    async (next: ReviewFilters, append: boolean) => {
      setLoading(true);
      try {
        const result = await getProductReviews(productId, next);
        setPage(result);
        setItems((prev) => (append ? [...prev, ...result.reviews] : result.reviews));
      } finally {
        setLoading(false);
      }
    },
    [productId],
  );

  // Re-fetch whenever a filter changes (but not on first mount — that's `initial`).
  const [mounted, setMounted] = useState(false);
  useEffect(() => {
    if (!mounted) {
      setMounted(true);
      return;
    }
    void load({ ...filters, page: 1 }, false);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.seller, filters.withImages, filters.rating]);

  const setFilter = (patch: Partial<ReviewFilters>) => setFilters((f) => ({ ...f, ...patch }));

  if (summary.count === 0) {
    return (
      <section id="degerlendirmeler" className="scroll-mt-24">
        <h2 className="text-lg font-extrabold tracking-tight">Değerlendirmeler</h2>
        <div className="mt-4 rounded-2xl border border-dashed border-ink-200 bg-white px-6 py-10 text-center dark:border-ink-700 dark:bg-ink-900">
          <p className="text-sm font-semibold text-ink-500">Bu ürün henüz değerlendirilmemiş.</p>
          <p className="mt-1 text-xs text-ink-400">Satın aldıysanız, ilk değerlendirmeyi siz yazabilirsiniz.</p>
        </div>
      </section>
    );
  }

  const maxBucket = Math.max(...Object.values(summary.distribution), 1);

  return (
    <section id="degerlendirmeler" className="scroll-mt-24">
      <h2 className="text-lg font-extrabold tracking-tight">
        Değerlendirmeler <span className="text-ink-400">({summary.count})</span>
      </h2>

      <div className="mt-4 grid gap-6 rounded-2xl border border-ink-100 bg-white p-5 dark:border-ink-800 dark:bg-ink-900 sm:grid-cols-[auto_1fr] sm:gap-8">
        {/* Average */}
        <div className="flex flex-col items-center justify-center gap-1 sm:pr-8 sm:border-r sm:border-ink-100 dark:sm:border-ink-800">
          <span className="text-4xl font-extrabold tracking-tight">{summary.average}</span>
          <Stars value={summary.average} className="text-lg" />
          <span className="text-xs text-ink-500">{summary.count} değerlendirme</span>
        </div>

        {/* Distribution */}
        <div className="flex flex-col justify-center gap-1.5">
          {(['5', '4', '3', '2', '1'] as const).map((star) => {
            const n = summary.distribution[star];
            return (
              <button
                key={star}
                type="button"
                onClick={() => setFilter({ rating: filters.rating === Number(star) ? undefined : Number(star) })}
                className={`flex items-center gap-2 text-left text-xs transition ${filters.rating === Number(star) ? 'font-bold' : 'text-ink-500 hover:text-ink-700 dark:hover:text-ink-200'}`}
              >
                <span className="w-6 tabular-nums">{star} ★</span>
                <span className="h-2 flex-1 overflow-hidden rounded-full bg-ink-100 dark:bg-ink-800">
                  <span className="block h-full rounded-full bg-amber-400" style={{ width: `${(n / maxBucket) * 100}%` }} />
                </span>
                <span className="w-8 text-right tabular-nums">{n}</span>
              </button>
            );
          })}
        </div>
      </div>

      {/* Filters */}
      <div className="mt-4 flex flex-wrap items-center gap-2.5">
        {summary.sellers.length > 1 && (
          <select
            value={filters.seller ?? ''}
            onChange={(e) => setFilter({ seller: e.target.value === '' ? undefined : e.target.value })}
            className="rounded-xl border-2 border-ink-200 bg-white px-3 py-2 text-sm font-bold text-ink-700 dark:border-ink-700 dark:bg-ink-900 dark:text-ink-200"
          >
            <option value="">Tüm satıcılar</option>
            {summary.sellers.map((s) => (
              <option key={s.id} value={s.id}>
                {s.name ?? 'Mağaza'} ({s.count})
              </option>
            ))}
          </select>
        )}

        <button
          type="button"
          onClick={() => setFilter({ withImages: filters.withImages ? undefined : true })}
          className={`rounded-xl border-2 px-3.5 py-2 text-sm font-bold transition ${filters.withImages ? 'border-brand-500 bg-brand-50 text-brand-700 dark:bg-brand-500/15' : 'border-ink-200 text-ink-600 hover:border-ink-300 dark:border-ink-700 dark:text-ink-300'}`}
        >
          📷 Sadece resimli
        </button>

        {(filters.rating !== undefined || filters.seller !== undefined || filters.withImages) && (
          <button
            type="button"
            onClick={() => setFilters({})}
            className="text-sm font-semibold text-ink-400 hover:text-brand-600"
          >
            Filtreleri temizle
          </button>
        )}
      </div>

      {/* Cards */}
      <ul className="mt-4 flex flex-col gap-3">
        {items.length === 0 && !loading && (
          <li className="rounded-2xl border border-dashed border-ink-200 px-5 py-8 text-center text-sm text-ink-500 dark:border-ink-700">
            Bu filtreye uyan değerlendirme yok.
          </li>
        )}
        {items.map((review) => (
          <ReviewCard key={review.id} review={review} />
        ))}
      </ul>

      {page.page < page.lastPage && (
        <div className="mt-4 text-center">
          <button
            type="button"
            disabled={loading}
            onClick={() => void load({ ...filters, page: page.page + 1 }, true)}
            className="rounded-xl border-2 border-ink-200 px-5 py-2.5 text-sm font-bold text-ink-600 transition hover:border-brand-400 hover:text-brand-600 disabled:opacity-50 dark:border-ink-700 dark:text-ink-300"
          >
            {loading ? 'Yükleniyor…' : 'Daha fazla değerlendirme'}
          </button>
        </div>
      )}
    </section>
  );
}

function ReviewCard({ review }: { review: ProductReview }) {
  return (
    <li className="rounded-2xl border border-ink-100 bg-white p-4 dark:border-ink-800 dark:bg-ink-900">
      <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
        <Stars value={review.rating} className="text-sm" />
        <span className="text-sm font-bold">{review.author_name}</span>
        {review.seller.name !== null && (
          <span className="rounded-full bg-ink-50 px-2 py-0.5 text-[.72rem] font-semibold text-ink-500 dark:bg-ink-800">
            {review.seller.name}
          </span>
        )}
        <span className="ml-auto text-xs text-ink-400">{formatDate(review.created_at)}</span>
      </div>

      {review.variant_label !== null && (
        <div className="mt-1 text-xs text-ink-400">{review.variant_label}</div>
      )}

      {review.body !== null && review.body !== '' && (
        <p className="mt-2 whitespace-pre-line text-[.9rem] leading-relaxed text-ink-700 dark:text-ink-200">{review.body}</p>
      )}

      {review.images.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-2">
          {review.images.map((image, i) => (
            <a key={i} href={image.large} target="_blank" rel="noopener noreferrer" className="block">
              {/* eslint-disable-next-line @next/next/no-img-element */}
              <img
                src={image.thumb}
                alt={`Değerlendirme görseli ${i + 1}`}
                loading="lazy"
                className="h-16 w-16 rounded-lg object-cover ring-1 ring-ink-100 transition hover:ring-brand-400 dark:ring-ink-700"
              />
            </a>
          ))}
        </div>
      )}
    </li>
  );
}

function formatDate(iso: string): string {
  try {
    return new Date(iso).toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' });
  } catch {
    return '';
  }
}
