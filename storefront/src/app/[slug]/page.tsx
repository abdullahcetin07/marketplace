import type { Metadata } from 'next';
import { notFound, permanentRedirect } from 'next/navigation';
import { BrandView } from '@/components/BrandView';
import { CategoryView } from '@/components/CategoryView';
import { ProductView } from '@/components/ProductView';
import { getBrand, getCategory, getProduct, resolveSlug, type ProductSort } from '@/lib/api';
import { absoluteUrl } from '@/lib/site';

/**
 * The flat-slug catch-all (ADR-059) — `/bioderma`, `/cilt-bakimi`,
 * `/avene-...-krem` all land here.
 *
 * ONE RESOLVE, THEN A BRANCH. The registry turns a slug into a type; this route
 * renders the matching view. It only ever sees slugs the app's own static routes
 * (`/sepet`, `/hesap`, …) did not claim — Next gives those precedence — and the
 * backend's reserved-word list guarantees no entity was ever issued one of those
 * slugs to begin with.
 *
 * A RETIRED ALIAS 301s TO ITS CANONICAL. `permanentRedirect` is a 308, so link
 * equity for an old slug flows to the new one rather than splitting between them.
 *
 * RENDERED PER REQUEST for the same reason every listing is — live price and
 * availability, indexable HTML (§2.1).
 */
export const dynamic = 'force-dynamic';

type Props = {
  params: Promise<{ slug: string }>;
  searchParams: Promise<Record<string, string | string[] | undefined>>;
};

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const match = await resolveSlug(slug);

  if (match === null) return { title: 'Bulunamadı' };

  const canonical = absoluteUrl(`/${match.canonical_slug}`);

  if (match.type === 'product') {
    const product = await getProduct(slug);
    if (product === null) return { title: 'Ürün bulunamadı' };

    return {
      title: product.title,
      description: product.description ?? undefined,
      alternates: { canonical },
      openGraph: {
        title: product.title,
        type: 'website',
        images: product.images.length > 0 ? [product.images[0] as string] : undefined,
      },
    };
  }

  if (match.type === 'category') {
    const category = await getCategory(slug);
    const name = category?.name ?? 'Kategori';

    return {
      title: name,
      description: `${name} kategorisindeki ürünler — en uygun fiyat ve orijinal ürün garantisiyle.`,
      alternates: { canonical },
    };
  }

  const brand = await getBrand(slug);
  const name = brand?.name ?? 'Marka';

  return {
    title: name,
    description: `${name} ürünleri — onaylı satıcılardan orijinal, en uygun fiyatla.`,
    alternates: { canonical },
  };
}

export default async function SlugPage({ params, searchParams }: Props) {
  const { slug } = await params;
  const sp = await searchParams;

  const match = await resolveSlug(slug);
  if (match === null) notFound();

  // A retired alias points at its live slug — send the crawler and the shopper there.
  if (match.canonical_slug !== slug) permanentRedirect(`/${match.canonical_slug}`);

  if (match.type === 'product') return <ProductView idOrSlug={slug} />;

  const page = Math.max(1, Number(single(sp.page) ?? 1) || 1);
  const sort = asSort(single(sp.sort));

  if (match.type === 'category') return <CategoryView slug={slug} page={page} sort={sort} />;
  if (match.type === 'brand') return <BrandView slug={slug} page={page} sort={sort} />;

  notFound();
}

function single(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[value.length - 1] : value;
}

function asSort(value: string | undefined): ProductSort {
  return value === 'price_asc' || value === 'price_desc' || value === 'newest' ? value : 'newest';
}
