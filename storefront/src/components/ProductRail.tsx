import { getBuyBoxPrices, getProductRatings, type ProductCard as Card } from '@/lib/api';
import { ProductCarousel } from '@/components/ProductCarousel';

/**
 * A carousel of pre-fetched product cards — the homepage ranking strips ("Çok
 * Satanlar", "En Çok Değerlendirilenler") whose ordering the caller has already
 * decided. A SERVER COMPONENT: it just adds the one batch price/rating read the
 * grids use and renders. Nothing when the list is empty, so a caller can drop
 * several in unguarded (they stay hidden until their backend endpoint has data).
 */
export async function ProductRail({ title, items, href }: { title: string; items: Card[]; href?: string }) {
  const products = items.slice(0, 8);

  if (products.length === 0) return null;

  const ids = products.map((product) => product.id);
  const [prices, ratings] = await Promise.all([getBuyBoxPrices(ids), getProductRatings(ids)]);

  return <ProductCarousel title={title} href={href} items={products} prices={prices} ratings={ratings} />;
}
