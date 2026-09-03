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
  // The product to leave out (the one being viewed). Optional: a /rehber carousel
  // is not hung off a product, so it excludes nothing — `id !== undefined` keeps all.
  excludeId?: string;
  href?: string;
}) {
  // DEGRADES TO NOTHING on any error. This is an optional rail — a slow or failing
  // browse must never crash the product page it hangs off (it lives behind a
  // Suspense boundary, which streams loading but does NOT catch a throw).
  try {
    const { items } = await browseProducts({ ...query, perPage: 16 });
    const products = items.filter((product) => product.id !== excludeId).slice(0, 8);

    if (products.length === 0) return null;

    const ids = products.map((product) => product.id);
    const [prices, ratings] = await Promise.all([getBuyBoxPrices(ids), getProductRatings(ids)]);

    return <ProductCarousel title={title} href={href} items={products} prices={prices} ratings={ratings} />;
  } catch {
    return null;
  }
}
