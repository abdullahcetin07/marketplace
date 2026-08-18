import type { MetadataRoute } from 'next';
import { browseProducts, fetchBrands, fetchCategoryTree, type CategoryNode } from '@/lib/api';
import { contentPages } from '@/lib/pages';
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

function flattenCategories(nodes: CategoryNode[]): string[] {
  return nodes.flatMap((node) => [node.slug, ...flattenCategories(node.children ?? [])]);
}

async function allProductSlugs(): Promise<string[]> {
  const slugs: string[] = [];

  // Walk the listing pages; capped so a large catalogue can't spin the build —
  // raise this into a sitemap index when it starts to bite.
  for (let page = 1; page <= 50; page++) {
    const result = await browseProducts({ page, perPage: 100 });
    slugs.push(...result.items.map((product) => product.slug));
    if (page >= result.lastPage) break;
  }

  return slugs;
}

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [tree, brands, productSlugs] = await Promise.all([
    fetchCategoryTree(),
    fetchBrands(),
    allProductSlugs(),
  ]);

  const staticPages: MetadataRoute.Sitemap = [
    { url: absoluteUrl('/'), changeFrequency: 'daily', priority: 1 },
    { url: absoluteUrl('/urunler'), changeFrequency: 'daily', priority: 0.8 },
    // The footer's content pages (Hakkımızda, SSS, KVKK…) — stable, low churn.
    ...Object.keys(contentPages).map((slug) => ({
      url: absoluteUrl(`/sayfa/${slug}`),
      changeFrequency: 'monthly' as const,
      priority: 0.3,
    })),
  ];

  const slugs = [...flattenCategories(tree), ...brands.map((brand) => brand.slug), ...productSlugs];

  const entries: MetadataRoute.Sitemap = slugs.map((slug) => ({
    url: absoluteUrl(`/${slug}`),
    changeFrequency: 'daily',
    priority: 0.7,
  }));

  return [...staticPages, ...entries];
}
