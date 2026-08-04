import Link from 'next/link';
import { notFound } from 'next/navigation';
import { Pagination } from '@/components/Pagination';
import { ProductGrid } from '@/components/ProductGrid';
import { browseProducts, getBrand, type ProductSort } from '@/lib/api';
import { absoluteUrl } from '@/lib/site';

/**
 * A brand landing (§2.2, ADR-059) — every product carrying one brand, at
 * `/{brand-slug}`. The other hub a shopper searches by name for.
 *
 * It renders even when nothing is in stock (the API returns the brand regardless):
 * a brand page that 404s the moment its last item sells out is a dead link a
 * bookmark and a search result both keep pointing at.
 */
export async function BrandView({
  slug,
  page,
  sort,
}: {
  slug: string;
  page: number;
  sort: ProductSort;
}) {
  const [brand, listing] = await Promise.all([
    getBrand(slug),
    browseProducts({ brand: slug, sort, page, perPage: 24 }),
  ]);

  if (brand === null) notFound();

  const breadcrumbLd = {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: [
      { name: 'Tüm ürünler', url: absoluteUrl('/urunler') },
      { name: brand.name, url: absoluteUrl(`/${brand.slug}`) },
    ].map((item, index) => ({ '@type': 'ListItem', position: index + 1, name: item.name, item: item.url })),
  };

  const hrefForPage = (next: number) => {
    const query = new URLSearchParams();
    if (sort !== 'newest') query.set('sort', sort);
    if (next > 1) query.set('page', String(next));

    return query.toString() === '' ? `/${slug}` : `/${slug}?${query.toString()}`;
  };

  return (
    <div className="flex flex-col gap-6">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(breadcrumbLd) }} />

      <nav aria-label="Yol" className="flex flex-wrap gap-1 text-sm text-ink-500">
        <Link href="/urunler" className="hover:text-brand-600">Tüm ürünler</Link>
        <span className="px-1">/</span>
        <span className="font-semibold text-ink-700 dark:text-ink-200">{brand.name}</span>
      </nav>

      <div className="flex items-center gap-4">
        {brand.logo ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={brand.logo} alt={brand.name} className="h-14 w-14 rounded-2xl object-contain ring-1 ring-ink-100 dark:ring-ink-800" />
        ) : (
          <span className="grid h-14 w-14 place-items-center rounded-2xl bg-brand-50 text-lg font-extrabold text-brand-700 dark:bg-brand-500/15">
            {brand.name.trim().slice(0, 2).toUpperCase()}
          </span>
        )}
        <div>
          <h1 className="text-[1.7rem] font-extrabold leading-tight tracking-tight sm:text-[1.9rem]">{brand.name}</h1>
          <p className="mt-1 text-sm text-ink-500">
            <span className="font-bold text-ink-700 dark:text-ink-200">{listing.total}</span> ürün
          </p>
        </div>
      </div>

      <ProductGrid products={listing.items} />

      <Pagination page={listing.page} lastPage={listing.lastPage} hrefForPage={hrefForPage} />
    </div>
  );
}
