import type { MetadataRoute } from 'next';
import { SITE_URL } from '@/lib/site';

/**
 * robots.txt — index the shop, keep the private/transactional pages out.
 *
 * A basket, a checkout, an account and the auth pages are per-person or dead ends
 * for a crawler; letting them into the index spends crawl budget on pages that
 * should never appear in a search result. Everything else is fair game, and the
 * sitemap points the way.
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      {
        userAgent: '*',
        allow: '/',
        disallow: ['/hesap', '/sepet', '/odeme', '/giris', '/kayit'],
      },
    ],
    sitemap: `${SITE_URL}/sitemap.xml`,
  };
}
