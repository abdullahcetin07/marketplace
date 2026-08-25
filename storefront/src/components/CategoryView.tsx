import Link from 'next/link';
import { notFound } from 'next/navigation';
import { ListingFilters } from '@/components/ListingFilters';
import { Pagination } from '@/components/Pagination';
import { ProductGrid } from '@/components/ProductGrid';
import { browseProducts, getCategory, type ProductSort } from '@/lib/api';
import { categoryContent } from '@/lib/category-content';
import { jsonLd } from '@/lib/jsonld';
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
          // The full result count across all pages, not just this page's slice —
          // tells the crawler the collection's real size.
          numberOfItems: listing.total,
          itemListElement: listing.items.map((item, index) => ({
            '@type': 'ListItem',
            position: (page - 1) * 24 + index + 1,
            url: absoluteUrl(`/${item.slug}`),
            name: item.title,
          })),
        }
      : null;

  // Curated buying guide + FAQ for the top categories, page 1 only (paginated pages
  // must not repeat the block — keeps it out of the ?page=N canonicals). The FAQ is
  // emitted as text (an accordion) AND as FAQPage JSON-LD so it is quotable by AI.
  const guide = page === 1 ? categoryContent[slug] : undefined;
  const faqLd =
    guide && guide.faq.length > 0
      ? {
          '@context': 'https://schema.org',
          '@type': 'FAQPage',
          mainEntity: guide.faq.map((f) => ({
            '@type': 'Question',
            name: f.q,
            acceptedAnswer: { '@type': 'Answer', text: f.a },
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
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(breadcrumbLd) }} />
      {itemListLd !== null && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(itemListLd) }} />
      )}
      {faqLd !== null && (
        <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLd(faqLd) }} />
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

      <ListingFilters facets={listing.facets} sort={sort} categories={category.children} />

      {/* Desktop keeps the sub-category pill row; on mobile these move into the
          ListingFilters "Kategoriler" sheet so they don't stack into many rows. */}
      {category.children.length > 0 && (
        <div className="hidden flex-wrap gap-2 sm:flex">
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

      {guide && (
        <section className="mt-4 flex flex-col gap-8 border-t border-ink-100 pt-8 dark:border-ink-800">
          <div className="max-w-3xl">
            <h2 className="text-lg font-extrabold tracking-tight">{category.name} — Alışveriş Rehberi</h2>
            <p className="mt-3 text-[.95rem] leading-relaxed text-ink-600 dark:text-ink-300">{guide.intro}</p>
          </div>

          {guide.faq.length > 0 && (
            <div className="max-w-3xl">
              <h2 className="text-lg font-extrabold tracking-tight">Sıkça Sorulan Sorular</h2>
              <div className="mt-3 flex flex-col gap-2">
                {guide.faq.map((item) => (
                  <details
                    key={item.q}
                    className="group rounded-xl border border-ink-100 bg-white px-4 py-3 dark:border-ink-800 dark:bg-ink-900"
                  >
                    <summary className="flex cursor-pointer list-none items-center justify-between gap-3 font-bold text-ink-800 marker:hidden dark:text-ink-100">
                      {item.q}
                      <svg
                        viewBox="0 0 24 24"
                        className="h-5 w-5 shrink-0 text-ink-400 transition-transform group-open:rotate-180"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth={2}
                        strokeLinecap="round"
                        strokeLinejoin="round"
                      >
                        <path d="m6 9 6 6 6-6" />
                      </svg>
                    </summary>
                    <p className="mt-2 text-[.92rem] leading-relaxed text-ink-600 dark:text-ink-300">{item.a}</p>
                  </details>
                ))}
              </div>
            </div>
          )}
        </section>
      )}
    </div>
  );
}
