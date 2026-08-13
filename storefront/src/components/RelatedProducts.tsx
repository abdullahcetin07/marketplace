import { browseProducts, getBuyBoxPrices, getProductRatings, type BrowseParams } from '@/lib/api';
import { ProductCarousel } from '@/components/ProductCarousel';

/**
 * A related-products carousel at the bottom of a product page — "Benzer Ürünler"
 * (same category) or "Önerilen Ürünler" (same brand). A SERVER COMPONENT, fetched
 * server-side like the grids, one batch price/rating read. Excludes the product
 * being viewed and renders nothing when nothing else is left, so a caller can drop
 * several in without guarding each.
 */
export async function RelatedProducts({
  title,
  query,
  excludeId,
  href,
}: {
  title: string;
  query: BrowseParams;
  excludeId: string;
  href?: string;
}) {
  const { items } = await browseProducts({ ...query, perPage: 16 });
  const products = items.filter((product) => product.id !== excludeId).slice(0, 8);

  if (products.length === 0) return null;

  const ids = products.map((product) => product.id);
  const [prices, ratings] = await Promise.all([getBuyBoxPrices(ids), getProductRatings(ids)]);

  return <ProductCarousel title={title} href={href} items={products} prices={prices} ratings={ratings} />;
}
