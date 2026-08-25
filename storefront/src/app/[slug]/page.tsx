import type { Metadata } from 'next';
import { notFound, permanentRedirect } from 'next/navigation';
import { BrandView } from '@/components/BrandView';
import { CategoryView } from '@/components/CategoryView';
import { ProductView } from '@/components/ProductView';
import { browseProducts, getBrand, getCategory, getProduct, resolveSlug, type ProductSort } from '@/lib/api';
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

export async function generateMetadata({ params, searchParams }: Props): Promise<Metadata> {
  const { slug } = await params;
  const sp = await searchParams;
  const match = await resolveSlug(slug);

  if (match === null) return { title: 'Bulunamadı' };

  const canonical = absoluteUrl(`/${match.canonical_slug}`);

  if (match.type === 'product') {
    const product = await getProduct(slug);
    if (product === null) return { title: 'Ürün bulunamadı' };

    // Many catalogue rows carry no editorial description; rather than ship a
    // product page with no meta description at all (Google then invents a snippet
    // from the page chrome), synthesize one from what we always know — brand,
    // category and title, so the line differs product to product instead of being
    // boilerplate across 20k pages. A blank string counts as absent (`?? ""` would
    // not fire), so `.trim() || …` falls through to it.
    const description =
      product.description?.trim() ||
      `${product.brand ? `${product.brand.name} ` : ''}${product.title} — ${product.category.name} kategorisinde onaylı satıcılardan orijinal ürün, en uygun fiyatla Raftabul’da. Güvenli ödeme, hızlı kargo.`;

    return {
      title: product.title,
      description,
      alternates: { canonical },
      openGraph: {
        title: product.title,
        // NOTE: og:type stays the site default "website". Next 15's Metadata API
        // VALIDATES openGraph.type against its own union at render time and THROWS
        // "Invalid OpenGraph type: product" for any value outside it (website,
        // article, book, profile, music.*, video.*) — a cast fools TypeScript but
        // not the runtime, which crashed every product page. The Product rich data
        // that actually matters is the JSON-LD Product/Offer block below, not og:type.
        images: product.images.length > 0 ? [product.images[0] as string] : undefined,
      },
    };
  }

  if (match.type === 'category') {
    const category = await getCategory(slug);
    const name = category?.name ?? 'Kategori';

    // A specific description reads better in results than boilerplate — name a few
    // of the category's own sub-categories when it has them, and the live product
    // count, so "Cilt Bakımı" advertises "342 ürün · temizleyici, nemlendirici, …"
    // rather than a line identical to every other category. The count is a cheap,
    // cached read that degrades to 0 (then it is simply omitted from the line).
    const childNames = category?.children.slice(0, 4).map((c) => c.name).join(', ');
    let count = 0;
    try {
      count = (await browseProducts({ category: slug, perPage: 1 })).total;
    } catch {
      count = 0;
    }
    const facts = [count > 0 ? `${count.toLocaleString('tr-TR')} ürün` : null, childNames || null]
      .filter(Boolean)
      .join(' · ');
    const description = facts
      ? `${name} kategorisinde ${facts} — onaylı satıcılardan orijinal ürün, en uygun fiyatlarla Raftabul’da.`
      : `${name} ürünleri — onaylı satıcılardan orijinal, en uygun fiyatlarla Raftabul’da.`;

    return {
      title: `${name} Ürünleri ve Fiyatları`,
      description,
      alternates: { canonical: listingCanonical(match.canonical_slug, sp) },
    };
  }

  const brand = await getBrand(slug);
  const name = brand?.name ?? 'Marka';

  return {
    title: `${name} Ürünleri ve Fiyatları`,
    description: `${name} ürünleri Raftabul’da — onaylı satıcılardan orijinal ${name} ürünlerini en uygun fiyatlarla keşfedin. Güvenli ödeme, hızlı kargo.`,
    alternates: { canonical: listingCanonical(match.canonical_slug, sp) },
  };
}

/**
 * The canonical URL for a category/brand listing (§SEO — pagination).
 *
 * A PURE `?page=N` (N>1) page SELF-CANONICALIZES so Google indexes page 2+ instead
 * of treating it as a duplicate of page 1 and dropping it. Page 1 stays the clean
 * `/{slug}` (no `?page=1`). A FILTERED variant (brand/price/non-default sort) keeps
 * collapsing to the clean hub — its existing de-dup, deliberately unchanged — so
 * only genuine pagination gains a self-reference.
 */
function listingCanonical(
  slug: string,
  sp: Record<string, string | string[] | undefined>,
): string {
  const page = Math.max(1, Number(single(sp.page) ?? 1) || 1);
  const sort = single(sp.sort);
  const filtered = Boolean(
    single(sp.brand) || single(sp.price_min) || single(sp.price_max) || (sort && sort !== 'newest'),
  );

  return page > 1 && !filtered
    ? absoluteUrl(`/${slug}?page=${page}`)
    : absoluteUrl(`/${slug}`);
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
  const priceMin = single(sp.price_min);
  const priceMax = single(sp.price_max);
  const brand = single(sp.brand);

  if (match.type === 'category')
    return <CategoryView slug={slug} page={page} sort={sort} priceMin={priceMin} priceMax={priceMax} brand={brand} />;
  if (match.type === 'brand')
    return <BrandView slug={slug} page={page} sort={sort} priceMin={priceMin} priceMax={priceMax} />;

  notFound();
}

function single(value: string | string[] | undefined): string | undefined {
  return Array.isArray(value) ? value[value.length - 1] : value;
}

function asSort(value: string | undefined): ProductSort {
  return value === 'price_asc' || value === 'price_desc' || value === 'newest' ? value : 'newest';
}
