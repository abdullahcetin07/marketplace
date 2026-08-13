import { getAlsoBought, getBuyBoxPrices, getProductRatings } from '@/lib/api';
import { ProductCarousel } from '@/components/ProductCarousel';

/**
 * "Bu Ürünü Alanlar Bunları da Aldı" — the co-purchase carousel on the product
 * page. A SERVER COMPONENT that reads the also-bought endpoint; it renders NOTHING
 * until that endpoint returns products (no purchase history → empty → hidden), so
 * the section appears on its own once sales accumulate. No wiring changes when the
 * backend ships the endpoint.
 */
export async function AlsoBought({ productId }: { productId: string }) {
  const products = (await getAlsoBought(productId)).slice(0, 8);

  if (products.length === 0) return null;

  const ids = products.map((product) => product.id);
  const [prices, ratings] = await Promise.all([getBuyBoxPrices(ids), getProductRatings(ids)]);

  return (
    <ProductCarousel
      title="Bu Ürünü Alanlar Bunları da Aldı"
      items={products}
      prices={prices}
      ratings={ratings}
    />
  );
}
