import { browseProducts, getBuyBoxPrices, getProductRatings } from '@/lib/api';
import { ProductCarousel } from '@/components/ProductCarousel';

/**
 * A curated, always-on homepage strip: the first 8 products of one category, as a
 * carousel. A SERVER COMPONENT — it fetches on the server (SSR + crawler-safe,
 * unlike the personalized strips), the same one-batch price/rating read the grids
 * use. Renders nothing when the category is empty, so a caller can list several
 * without guarding each.
 */
export async function CategoryStrip({ title, slug, href }: { title: string; slug: string; href?: string }) {
  // DEGRADES TO NOTHING on any error — a slow/failing browse must not crash the
  // homepage this strip sits on (it renders behind a Suspense boundary).
  try {
    const { items } = await browseProducts({ category: slug, perPage: 12 });
    const products = items.slice(0, 8);

    if (products.length === 0) return null;

    const ids = products.map((product) => product.id);
    const [prices, ratings] = await Promise.all([getBuyBoxPrices(ids), getProductRatings(ids)]);

    return <ProductCarousel title={title} href={href} items={products} prices={prices} ratings={ratings} />;
  } catch {
    return null;
  }
}
