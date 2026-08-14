import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ListingFilters } from '@/components/ListingFilters';
import { Pagination } from '@/components/Pagination';
import { ProductGrid } from '@/components/ProductGrid';
import { browseProducts, getCategory, type ProductSort } from '@/lib/api';
import { absoluteUrl } from '@/lib/site';

/**
 * A category landing (§2.2, ADR-059) — the hub page a head term ("cilt bakımı")
 * should rank on.
 *
 * IT IS A CATEGORY DOC + A FILTERED LISTING. `/categories/{slug}` gives the
 * breadcrumb and the sub-categories to drill into; the browse call, filtered by the
 * same slug, gives the products — matched across the whole subtree by the API, so a
 * parent category shows everything beneath it.
 *
 * BreadcrumbList JSON-LD is emitted from the path, so the crawler sees the same
 * trail the shopper does.
 */
export async function CategoryView({
  slug,
  page,
  sort,
  priceMin,
  priceMax,
  brand,
}: {
  slug: string;
  page: number;
  sort: ProductSort;
  priceMin?: string;
  priceMax?: string;
  brand?: string;
}) {
  const [category, listing] = await Promise.all([
    getCategory(slug),
    browseProducts({ category: slug, brand, priceMin, priceMax, sort, page, perPage: 24 }),
  ]);

  if (category === null) notFound();

  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { name: 'Ana Sayfa', url: absoluteUrl('/') },
      ...category.path.map((node) => ({ name: node.name, url: absoluteUrl(`/${node.slug}`) })),
    ].map((item, index) => ({ '@type': 'ListItem', position: index + 1, name: item.name, item: item.url })),
  };

  // The products on THIS page as an ItemList, so the crawler reads the category as
  // a collection of named entries rather than a wall of links. Positions continue
  // across pages (page 2 starts at 25) so paginated pages don't all claim rank 1.
  const itemListLd =
    listing.items.length > 0
      ? {
          '@context': 'https://schema.org',
          '@type': 'ItemList',
          itemListElement: listing.items.map((item, index) => ({
            '@type': 'ListItem',
            position: (page - 1) * 24 + index + 1,
            url: absoluteUrl(`/${item.slug}`),
            name: item.title,
          })),
        }
      : null;

  const hrefForPage = (next: number) => {
    const query = new URLSearchParams();
    if (sort !== 'newest') query.set('sort', sort);
    if (brand) query.set('brand', brand);
    if (priceMin) query.set('price_min', priceMin);
    if (priceMax) query.set('price_max', priceMax);
    if (next > 1) query.set('page', String(next));

    return query.toString() === '' ? `/${slug}` : `/${slug}?${query.toString()}`;
  };

  return (
    <div className="flex flex-col gap-6">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }} />
      {itemListLd !== null && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(itemListLd) }} />
      )}

      <nav aria-label="Kategori yolu" className="flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/" className="hover:text-brand-600">Ana Sayfa</Link>
        {category.path.map((node) => (
          <span key={node.id}>
            <span className="px-1">/</span>
            {node.slug === slug ? (
              <span className="font-semibold text-ink-700 dark:text-ink-200">{node.name}</span>
            ) : (
              <Link href={`/${node.slug}`} className="hover:text-brand-600">{node.name}</Link>
            )}
          </span>
        ))}
      </nav>

      <div>
        <h1 className="text-[1.7rem] font-extrabold leading-tight tracking-tight sm:text-[1.9rem]">{category.name}</h1>
        <p className="mt-1 text-sm text-ink-500">
          <span className="font-bold text-ink-700 dark:text-ink-200">{listing.total}</span> ürün
        </p>
      </div>

      <ListingFilters facets={listing.facets} sort={sort} />

      {category.children.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {category.children.map((child) => (
            <Link
              key={child.id}
              href={`/${child.slug}`}
              className="rounded-full border-2 border-ink-200 px-4 py-2 text-sm font-bold text-ink-600 transition hover:border-brand-400 hover:text-brand-600 dark:border-ink-700 dark:text-ink-300"
            >
              {child.name}
              <span className="ml-1.5 text-xs font-semibold text-ink-400">{child.product_count}</span>
            </Link>
          ))}
        </div>
      )}

      <ProductGrid products={listing.items} />

      <Pagination page={listing.page} lastPage={listing.lastPage} hrefForPage={hrefForPage} />
    </div>
  );
}
