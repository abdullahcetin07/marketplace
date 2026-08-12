import type { HeroSlide } from '@/components/HeroSlider';

/**
 * Homepage hero campaign banners (the slider).
 *
 * HOW TO ADD ONE:
 *   1. Drop a wide banner image into `public/kampanyalar/` —
 *      recommended 1600×500 px (≈16:5), JPG or WebP, under ~400 KB.
 *   2. Add an entry below. `href` is where the banner links (a category, brand,
 *      listing or product URL); omit it for a non-clickable banner.
 *
 * There is no campaigns API — these are static merchandising assets, so the list
 * lives here. An empty list makes the homepage show a plain marketplace panel
 * instead of the slider.
 */
export const campaigns: HeroSlide[] = [
  // { image: '/kampanyalar/1.jpg', href: '/urunler', alt: 'Elektronikte büyük indirim' },
  // { image: '/kampanyalar/2.jpg', href: '/moda',    alt: 'Moda haftası fırsatları' },
];
