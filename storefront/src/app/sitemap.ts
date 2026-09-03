import type { MetadataRoute } from 'next';
import { allStoreSlugs, browseProducts, fetchBrands, fetchCategoryTree, type CategoryNode } from '@/lib/api';
import { contentPages } from '@/lib/pages';
import { GUIDES } from '@/lib/guides';
import { absoluteUrl } from '@/lib/site';

/**
 * The sitemap — every flat slug a crawler should find (ADR-059).
 *
 * Categories (the whole tree), brands, and products all live at the root, so the
 * map is their slugs plus the two static listing pages. Rebuilt hourly; a single
 * document is fine at this catalogue size — a sitemap index is the follow-up when
 * the product count outgrows one file.
 *
 * GENERATED ON DEMAND, not at build: it reads the live catalogue, and the build
 * host has no API to reach. A crawler fetches it rarely, so per-request is fine.
 */
export const dynamic = 'force-dynamic';

/**
 * A STABLE lastmod for the static/informational pages (`/`, `/urunler`, `/sayfa/*`).
 *
 * These pages have no per-entity `updated_at`, so they used `now()` — which changed
 * on every fetch and told crawlers the homepage was edited each time the sitemap was
 * requested, training them to distrust the signal. A fixed date (bumped by hand when
 * this content actually changes) is honest. Catalogue and store entries keep their
 * real `updated_at`.
 */
const STATIC_LASTMOD = new Date('2026-08-21');

/** A catalogue URL for the sitemap, with the entity's own last-modified date. */
type CatalogRef = { slug: string; updated_at?: string };

function flattenCategories(nodes: CategoryNode[]): CatalogRef[] {
  return nodes.flatMap((node) => [
    { slug: node.slug, updated_at: node.updated_at },
    ...flattenCategories(node.children ?? []),
  ]);
}

async function allProductRefs(): Promise<CatalogRef[]> {
  const refs: CatalogRef[] = [];

  // Walk the listing pages; capped so a large catalogue can't spin the build —
  // raise this into a sitemap index when it starts to bite.
  for (let page = 1; page <= 50; page++) {
    const result = await browseProducts({ page, perPage: 100 });
    refs.push(...result.items.map((product) => ({ slug: product.slug, updated_at: product.updated_at })));
    if (page >= result.lastPage) break;
  }

  return refs;
}

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [tree, brands, productRefs, stores] = await Promise.all([
    fetchCategoryTree(),
    fetchBrands(),
    allProductRefs(),
    allStoreSlugs(),
  ]);

  const staticPages: MetadataRoute.Sitemap = [
    { url: absoluteUrl('/'), lastModified: STATIC_LASTMOD, changeFrequency: 'daily', priority: 1 },
    { url: absoluteUrl('/urunler'), lastModified: STATIC_LASTMOD, changeFrequency: 'daily', priority: 0.8 },
    { url: absoluteUrl('/rehber'), lastModified: STATIC_LASTMOD, changeFrequency: 'weekly', priority: 0.5 },
    // The footer's content pages (Hakkımızda, SSS, KVKK…) — stable, low churn.
    ...Object.keys(contentPages).map((slug) => ({
      url: absoluteUrl(`/sayfa/${slug}`),
      lastModified: STATIC_LASTMOD,
      changeFrequency: 'monthly' as const,
      priority: 0.3,
    })),
  ];

  // Categories, brands and products all live at the root slug. Each carries its own
  // `updated_at` now (the entity's content date — a product's price/stock lives on the
  // Offer, ADR-042, so it does NOT move this), giving an honest per-URL lastmod.
  const catalog: CatalogRef[] = [
    ...flattenCategories(tree),
    ...brands.map((brand) => ({ slug: brand.slug, updated_at: brand.updated_at })),
    ...productRefs,
  ];

  const entries: MetadataRoute.Sitemap = catalog.map((ref) => ({
    url: absoluteUrl(`/${ref.slug}`),
    lastModified: ref.updated_at ? new Date(ref.updated_at) : undefined,
    changeFrequency: 'daily',
    priority: 0.7,
  }));

  // Store pages carry a real `updated_at` from the backend, so they get an honest
  // lastmod (unlike the catalogue entries above).
  const storeEntries: MetadataRoute.Sitemap = stores.map((store) => ({
    url: absoluteUrl(`/magaza/${store.slug}`),
    lastModified: store.updated_at ? new Date(store.updated_at) : undefined,
    changeFrequency: 'weekly',
    priority: 0.5,
  }));

  // /rehber articles — editorial content with their own last-updated date.
  const guideEntries: MetadataRoute.Sitemap = GUIDES.map((g) => ({
    url: absoluteUrl(`/rehber/${g.slug}`),
    lastModified: new Date(g.updated),
    changeFrequency: 'monthly',
    priority: 0.5,
  }));

  return [...staticPages, ...entries, ...storeEntries, ...guideEntries];
}
