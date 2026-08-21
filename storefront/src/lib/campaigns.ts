import type { HeroSlide } from '@/components/HeroSlider';

/**
 * Homepage hero campaign banners (the slider).
 *
 * HOW TO ADD ONE:
 *   1. Drop a wide banner image into `public/kampanyalar/` —
 *      recommended 1600×500 px (≈16:5), JPG or WebP, under ~400 KB.
 *   2. (Optional, recommended) Drop a MOBILE banner too — designed for phones,
 *      e.g. 1080×1080 (1:1) or 1080×810 (4:3). It shows at its own ratio, uncropped,
 *      under 640px; add it as `mobileImage`. Use the SAME size for every slide so
 *      the slider height stays consistent. Without one, mobile crops the wide image
 *      to 2:1.
 *   3. Add an entry below. `href` is where the banner links (a category, brand,
 *      listing or product URL); omit it for a non-clickable banner.
 *
 * There is no campaigns API — these are static merchandising assets, so the list
 * lives here. An empty list makes the homepage show a plain marketplace panel
 * instead of the slider.
 */
export const campaigns: HeroSlide[] = [
  { image: '/kampanyalar/magnezyum-slider.webp', href: '/magnezyum-mineralleri', alt: 'Magnezyumlarda Büyük İndirim Fırsatı' },
  { image: '/kampanyalar/cilt-bakim.webp', href: '/cilt-bakimi', alt: 'Cilt Bakım haftası fırsatları' },
  { image: '/kampanyalar/nutraxin.webp', href: '/nutraxin', alt: 'Nutraxin ile Sağlığın için güçlü destek' },
];
