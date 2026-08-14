import Link from 'next/link';
import type { BuyBoxPrices, ProductCard as Card, ProductRatings } from '@/lib/api';
import { formatMoney } from '@/lib/money';

/**
 * One product in a listing — content from Catalog, price from Offer (ADR-058).
 *
 * THE PRICE IS PASSED IN, NOT FETCHED HERE. A card fetching its own price would
 * be one request per row on the busiest page the platform has; the listing asks
 * once for the whole page and hands each card its entry.
 *
 * "₺X'den başlayan" IS THE HONEST WORDING when several sellers compete for the
 * same product: the number shown is the cheapest one, and a shopper who lands on
 * the page must not find it higher than what the card promised.
 *
 * A MISSING PRICE MEANS NOBODY IS SELLING IT RIGHT NOW. It should be unreachable
 * on this surface — the listing only lists sellable products — so it renders as
 * a quiet state rather than an error, for the seconds between a seller's last
 * unit going and the cache catching up.
 */
export function ProductCard({
  product,
  prices,
  ratings,
}: {
  product: Card;
  prices: BuyBoxPrices;
  ratings?: ProductRatings;
}) {
  const price = prices[product.id];
  const rating = ratings?.[product.id];

  return (
    <Link
      href={`/${product.slug}`}
      className="group flex flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white transition hover:-translate-y-1 hover:border-transparent hover:shadow-[0_20px_44px_-20px_rgba(20,25,35,.28)] dark:border-ink-800 dark:bg-ink-900"
    >
      {/* overflow-hidden keeps the square authoritative — without it a portrait image
          (in normal flow) pushes the aspect-square box taller and the card grows
          unevenly next to its neighbours. */}
      <div className="relative aspect-square overflow-hidden bg-white p-4 dark:bg-ink-50">
        {product.image === null ? (
          // A placeholder, not a broken image: plenty of real listings have no
          // photograph yet and a missing file should not look like a fault.
          <div className="flex h-full items-center justify-center text-sm text-ink-400">
            görsel yok
          </div>
        ) : (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={product.image}
            alt={product.title}
            className="h-full w-full object-contain mix-blend-multiply transition group-hover:scale-[1.04]"
            loading="lazy"
          />
        )}
      </div>

      <div className="flex flex-1 flex-col gap-1.5 p-3.5">
        {product.brand !== null && (
          <span className="text-[.72rem] font-extrabold text-ink-900 dark:text-ink-100">
            {product.brand.name}
          </span>
        )}

        <h3 className="line-clamp-2 min-h-[2.4em] text-sm leading-snug text-ink-600 dark:text-ink-300">
          {product.title}
        </h3>

        {rating !== undefined && rating.count > 0 && (
          <span className="flex items-center gap-1 text-[.72rem] font-bold text-ink-500">
            <svg viewBox="0 0 20 20" className="h-3.5 w-3.5 text-amber-400" fill="currentColor">
              <path d="M10 1.6l2.47 5.01 5.53.8-4 3.9.94 5.5L10 14.2l-4.95 2.6.94-5.5-4-3.9 5.53-.8z" />
            </svg>
            <span className="tabular-nums text-ink-700 dark:text-ink-200">{rating.average}</span>
            <span className="text-ink-400">({rating.count})</span>
          </span>
        )}

        {price !== undefined && price.seller_count > 1 && (
          <span className="text-[.72rem] font-bold text-green-600">{price.seller_count} satıcı</span>
        )}

        <div className="mt-auto pt-2">
          {price === undefined ? (
            <span className="text-sm font-semibold text-ink-500">şu an satışta yok</span>
          ) : (
            <div className="flex flex-col">
              {price.list_price !== null && (
                <span className="text-[.78rem] text-ink-400 line-through">
                  {formatMoney(price.list_price, price.currency)}
                </span>
              )}
              <span className="flex items-baseline gap-1 text-[1.28rem] font-extrabold tracking-tight text-brand-600">
                {formatMoney(price.from_price, price.currency)}
                <span className="text-[.72rem] font-semibold text-ink-500">&apos;den başlayan</span>
              </span>
            </div>
          )}
        </div>
      </div>
    </Link>
  );
}
