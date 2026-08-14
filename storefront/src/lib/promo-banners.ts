export type PromoBanner = {
  /** Image under /public — e.g. "/banner/gunes-1.webp". */
  image: string;
  /** Where the banner links (a category/brand/product/listing URL). Omit for non-clickable. */
  href?: string;
  /** Alt text — also the accessible name. */
  alt: string;
};

export type PromoBlock = {
  /** A row of up to 3 banners. */
  triple?: PromoBanner[];
  /** One wide banner below the row, shown 135px tall. */
  wide?: PromoBanner;
};

/**
 * Promo banner blocks shown AFTER a homepage category strip, keyed by that
 * category's slug. Each block = a row of up to 3 banners + one wide banner under it.
 *
 * IMAGE SIZES (drop files in public/banner/, JPG or WebP):
 *   • 3'lü banner (each):  600 × 400 px  (3:2) — shown ~410px wide, object-cover.
 *                          (use 900 × 600 for a sharper image.)
 *   • Tekli geniş banner:  1920 × 210 px       — shown full width at 135px tall,
 *                          object-cover (crops a little top/bottom — centre the content).
 *
 * An empty entry (or `{}`) hides that part, so a block appears only once its images
 * are configured. Keyed by the exact category slug from CATEGORY_STRIPS.
 */
export const promoBlocks: Record<string, PromoBlock> = {
  'gunes-kremleri': {
    // triple: [
    //   { image: '/banner/gunes-1.webp', href: '/gunes-kremleri', alt: 'Güneş kremlerinde fırsat' },
    //   { image: '/banner/gunes-2.webp', href: '/cocuk-gunes-urunleri', alt: 'Çocuk güneş ürünleri' },
    //   { image: '/banner/gunes-3.webp', href: '/gunes-spreyleri', alt: 'Güneş spreyleri' },
    // ],
    // wide: { image: '/banner/gunes-wide.webp', href: '/gunes-kremleri', alt: 'Güneş bakım kampanyası' },
  },
  'besin-takviyeleri': {},
  'cilt-bakimi': {},
};
