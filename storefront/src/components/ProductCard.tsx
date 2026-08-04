import Link from 'next/link';
import type { BuyBoxPrices, ProductCard as Card } from '@/lib/api';
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
export function ProductCard({ product, prices }: { product: Card; prices: BuyBoxPrices }) {
  const price = prices[product.id];

  return (
    <Link
      href={`/${product.slug}`}
      className="group flex flex-col overflow-hidden rounded-2xl border border-ink-100 bg-white transition hover:-translate-y-1 hover:border-transparent hover:shadow-[0_20px_44px_-20px_rgba(20,25,35,.28)] dark:border-ink-800 dark:bg-ink-900"
    >
      <div className="relative aspect-square bg-white p-4 dark:bg-ink-50">
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
