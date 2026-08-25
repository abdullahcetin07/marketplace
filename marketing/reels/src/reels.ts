import type { ReelData } from './types';

/**
 * Every reel Raftabul renders. Each is 1 hook + N steps + 1 CTA; the template
 * sizes the video and the progress bar from the scene count automatically.
 *
 * COPY RULES (raftabul-social + raftabul-product-copy):
 *  - No disease/treatment claims. "nemlendirmeye/korumaya yardımcı", not "tedavi".
 *  - Product images come from OUR catalogue only (public API). No licensed stock.
 *  - Music is added in the Instagram app on upload (licensed, free) — never baked in.
 */
export const REELS: ReelData[] = [
  {
    id: 'monday',
    scenes: [
      {
        kind: 'hook',
        eyebrow: 'CİLT BAKIMI',
        line1: 'Yaz cildini',
        line2: 'yordu mu?',
        sub: '3 adımda tazele ↓',
        image: 'products/serum.webp',
      },
      { kind: 'step', n: '1', title: 'Temizle', desc: 'Nazik bir tonikle günün kirini arındır.', image: 'products/tonik.webp' },
      { kind: 'step', n: '2', title: 'Nemlendir', desc: 'Nemlendiriciyi nemli cilde uygula — nem hapsolsun.', image: 'products/krem.webp' },
      { kind: 'step', n: '3', title: 'Koru', desc: 'Gündüz SPF50 güneş koruyucu — eylülde bile.', image: 'products/gunes.webp' },
      {
        kind: 'cta',
        eyebrow: 'RAFTABUL',
        line1: 'Onaylı satıcılardan',
        line2: 'orijinal ürün',
        sub: 'Tüm siparişlerde kargo bedava · Aldıkça puan kazan',
        images: ['products/tonik.webp', 'products/krem.webp', 'products/gunes.webp'],
      },
    ],
  },
  {
    id: 'friday',
    scenes: [
      {
        kind: 'hook',
        eyebrow: 'GÜNEŞ KORUYUCU',
        line1: 'Güneş kreminde',
        line2: '3 yaygın hata',
        sub: 'İzle, doğrusunu öğren ↓',
        image: 'products/gunes.webp',
      },
      { kind: 'step', n: '1', title: 'Az sürmek', desc: 'Yüz için yaklaşık bir fındık miktarı gerekir — cimrilik koruma bırakmaz.' },
      { kind: 'step', n: '2', title: 'Tazelememek', desc: '2-3 saatte bir yenile; denizde, terde daha sık.' },
      { kind: 'step', n: '3', title: 'Bulutlu günü atlamak', desc: 'UV ışınları bulutu geçer — yaz kış, her gün.' },
      {
        kind: 'cta',
        eyebrow: 'RAFTABUL',
        line1: 'SPF50 güneş koruyucular',
        line2: 'orijinal, en uygun fiyata',
        sub: 'Tüm siparişlerde kargo bedava · Aldıkça puan kazan',
        images: ['products/gunes.webp'],
      },
    ],
  },
];
