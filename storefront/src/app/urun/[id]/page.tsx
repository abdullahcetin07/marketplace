import { notFound, permanentRedirect } from 'next/navigation';
import { getProduct } from '@/lib/api';

/**
 * The retired product URL (ADR-059).
 *
 * `/urun/{uuid}` was the address before flat slugs; links to it exist in the wild
 * (bookmarks, a shared uuid), so it stays — as a 301 to the canonical `/{slug}`
 * rather than a second live copy of the page. Two URLs rendering the same product
 * is the duplicate-content split the slug scheme exists to avoid.
 */
export const dynamic = 'force-dynamic';

export default async function LegacyProductPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const product = await getProduct(id);

  if (product === null) notFound();

  permanentRedirect(`/${product.slug}`);
}
