import { getBuyBoxPrices, type ProductCard as Card } from '@/lib/api';
import { ProductCard } from './ProductCard';

/**
 * A page of cards, with their prices fetched ONCE (Storefront.md §1.2).
 *
 * THIS COMPONENT IS WHERE THE COMPOSITION HAPPENS. Catalog gave the cards and no
 * price; one batch call gives every price on the page. Doing it here rather than
 * in each page means every listing — home, search, a category — gets the same
 * single round trip without remembering to.
 */
export async function ProductGrid({ products }: { products: Card[] }) {
  if (products.length === 0) {
    return (
      <p className="rounded-xl border border-dashed border-ink-300 p-12 text-center text-ink-500 dark:border-ink-700">
        Aradığınız kriterlere uyan ürün bulunamadı.
      </p>
    );
  }

  const prices = await getBuyBoxPrices(products.map((product) => product.id));

  return (
    <div className="grid grid-cols-2 gap-3.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
      {products.map((product) => (
        <ProductCard key={product.id} product={product} prices={prices} />
      ))}
    </div>
  );
}
