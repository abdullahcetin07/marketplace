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
      href={`/urun/${product.id}`}
      className="group flex flex-col overflow-hidden rounded-xl border border-ink-200 transition hover:border-brand-300 hover:shadow-lg dark:border-ink-800"
    >
      <div className="aspect-square overflow-hidden bg-ink-50 dark:bg-ink-900">
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
            className="h-full w-full object-cover transition group-hover:scale-105"
            loading="lazy"
          />
        )}
      </div>

      <div className="flex flex-1 flex-col gap-1 p-4">
        {product.brand !== null && (
          <span className="text-xs font-medium uppercase tracking-wide text-ink-500">
            {product.brand.name}
          </span>
        )}

        <h3 className="line-clamp-2 font-medium leading-snug">{product.title}</h3>

        <div className="mt-auto pt-3">
          {price === undefined ? (
            <span className="text-sm text-ink-500">şu an satışta yok</span>
          ) : (
            <span className="text-lg font-bold text-brand-600">
              {formatMoney(price.from_price, price.currency)}
              <span className="ml-1 text-xs font-normal text-ink-500">'den başlayan</span>
            </span>
          )}
        </div>
      </div>
    </Link>
  );
}
