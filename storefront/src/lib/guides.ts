/**
 * The /rehber content hub (SEO — long-tail informational surface).
 *
 * Guides are curated editorial content, not user data — so they live here as typed
 * data rather than in a CMS: versioned with the code, fully controllable for SEO
 * structure (H2/H3, comparison tables, FAQ schema) and safe to render. Each guide
 * ties back to the catalogue through `carousel` blocks (a category/brand product
 * rail), so an informational read becomes a shopping path.
 *
 * ⚖️ HEALTH-CLAIM RULE (raftabul-product-copy): cosmetics/supplements make NO disease
 * treatment/prevention claim. Guides describe brand focus, textures and skin-type fit
 * — never "tedavi eder / geçirir". Symptom-adjacent topics carry the doctor disclaimer.
 */

export type GuideBlock =
  | { type: 'p'; text: string }
  | { type: 'ul'; items: string[] }
  | { type: 'table'; headers: string[]; rows: string[][] }
  | { type: 'carousel'; title: string; href: string; brand?: string; category?: string };

export type GuideSection = { heading: string; blocks: GuideBlock[] };

export type Guide = {
  slug: string;
  title: string;
  metaDescription: string;
  /** ISO date — surfaced as a freshness signal and in Article schema. */
  updated: string;
  /** One-line teaser for the hub index card. */
  teaser: string;
  intro: string[];
  sections: GuideSection[];
  faq: { q: string; a: string }[];
};

export const GUIDES: Guide[] = [
  {
    slug: 'avene-bioderma-la-roche-posay-karsilastirma',
    title: 'Avène, Bioderma, La Roche-Posay: Hangisi Cildinize Uygun?',
    metaDescription:
      'Avène, Bioderma ve La Roche-Posay dermokozmetik markalarını karşılaştırdık: hangi marka hangi cilt tipine ve bakım ihtiyacına odaklanıyor, ikonik ürünleri neler? Cilt tipine göre seçim rehberi.',
    updated: '2026-09-03',
    teaser:
      'Üç büyük Fransız dermokozmetik markasının odak noktaları, ikonik ürünleri ve hangi cilt tipine hitap ettiği — cilt tipine göre seçim rehberi.',
    intro: [
      'Dermokozmetikte en çok tercih edilen üç Fransız markası Avène, Bioderma ve La Roche-Posay. Üçü de eczane kozmetiği kategorisinde, farklı cilt tiplerine ve bakım ihtiyaçlarına yönelik geniş bir ürün yelpazesi sunuyor. Peki hangisi size uygun? Bu rehberde üç markanın odak noktalarını, ikonik ürünlerini ve hangi cilt tipine hitap ettiğini karşılaştırdık.',
      'Not: Aşağıdaki karşılaştırma markaların ürün konumlandırması ve içerik odağına dayanır. Kozmetik ürünler hastalıkların tedavisi için kullanılmaz; belirgin cilt sorunlarınız için bir dermatoloğa danışın.',
    ],
    sections: [
      {
        heading: 'Kısa karşılaştırma',
        blocks: [
          {
            type: 'table',
            headers: ['Marka', 'Öne çıkan odak', 'İkonik ürün', 'En uygun cilt tipi'],
            rows: [
              ['Avène', 'Termal su, yatıştırıcı sadelik', 'Eau Thermale, Cleanance', 'Hassas · reaktif · yağlı/akneye eğilimli'],
              ['Bioderma', 'Misel teknolojisi, cilt ekolojisi', 'Sensibio H2O, Sébium', 'Hassas · yağlı/karma · kuru'],
              ['La Roche-Posay', 'Geniş dermatolojik yelpaze', 'Effaclar, Anthelios', 'Yağlı/akneye eğilimli · hassas · güneş'],
            ],
          },
        ],
      },
      {
        heading: 'Avène — hassas ve reaktif ciltlerin sadeliği',
        blocks: [
          {
            type: 'p',
            text: 'Avène, ürünlerinin merkezine kendi kaynağından gelen Avène termal suyunu koyar; yatıştırıcı, sade ve az bileşenli formülleriyle özellikle hassas ve reaktif ciltlere hitap eder. Yağlı ve akneye eğilimli ciltler için Cleanance serisi, kuru ve atopiye eğilimli ciltler için XeraCalm serisi öne çıkar.',
          },
          {
            type: 'ul',
            items: [
              'Eau Thermale (termal su): ferahlatıcı, yatıştırıcı bakım.',
              'Cleanance: yağlı ve akneye eğilimli ciltlerin bakımına odaklı.',
              'XeraCalm A.D.: kuru ve atopiye eğilimli ciltler için.',
            ],
          },
          { type: 'carousel', title: 'Avène ürünleri', href: '/avene', brand: 'avene' },
        ],
      },
      {
        heading: 'Bioderma — misel suyun mucidi',
        blocks: [
          {
            type: 'p',
            text: '"Biyolojiyi cildin hizmetine sunmak" yaklaşımıyla bilinen Bioderma, misel makyaj temizleme suyunu popülerleştiren markadır. Sensibio H2O, hassas ciltler için adeta bir referans ürün. Yağlı ve karma ciltler için Sébium, kuru ve atopiye eğilimli ciltler için Atoderm, nem ihtiyacı için Hydrabio serileriyle geniş bir yelpaze sunar.',
          },
          {
            type: 'ul',
            items: [
              'Sensibio H2O: hassas ciltler için ikonik misel temizleme suyu.',
              'Sébium: yağlı ve karma ciltlerin bakımına odaklı.',
              'Atoderm: kuru ve atopiye eğilimli ciltler için.',
            ],
          },
          { type: 'carousel', title: 'Bioderma ürünleri', href: '/bioderma', brand: 'bioderma' },
        ],
      },
      {
        heading: 'La Roche-Posay — geniş dermatolojik yelpaze',
        blocks: [
          {
            type: 'p',
            text: 'La Roche-Posay, kendi termal suyu ve dermatologlarla iş birliği vurgusuyla en geniş yelpazelerden birine sahiptir. Yağlı ve akneye eğilimli ciltler için Effaclar, hassas ve intoleran ciltler için Toleriane, güneş koruması için Anthelios, yaşlanma karşıtı bakım için Hyalu B5 ve Retinol B3, leke görünümü için Mela B3 öne çıkan serilerdir.',
          },
          {
            type: 'ul',
            items: [
              'Effaclar: yağlı ve akneye eğilimli ciltlerin bakımına odaklı.',
              'Anthelios: geniş güneş koruma yelpazesi.',
              'Toleriane: hassas ve intoleran ciltler için.',
            ],
          },
          { type: 'carousel', title: 'La Roche-Posay ürünleri', href: '/la-roche-posay', brand: 'la-roche-posay' },
        ],
      },
      {
        heading: 'Cilt tipine ve ihtiyaca göre hangisi?',
        blocks: [
          {
            type: 'ul',
            items: [
              'Hassas ve reaktif cilt: Avène (termal su odağı) veya La Roche-Posay Toleriane; Bioderma Sensibio.',
              'Yağlı ve akneye eğilimli cilt: üçü de güçlü — Bioderma Sébium, Avène Cleanance, La Roche-Posay Effaclar.',
              'Kuru ve atopiye eğilimli cilt: Avène XeraCalm, Bioderma Atoderm.',
              'Makyaj temizleme / misel su: Bioderma Sensibio H2O.',
              'Güneş koruması: La Roche-Posay Anthelios geniş yelpaze sunar.',
              'Leke görünümü bakımı: La Roche-Posay Mela B3, Bioderma Pigmentbio.',
            ],
          },
          { type: 'carousel', title: 'Cilt bakımı ürünleri', href: '/cilt-bakimi', category: 'cilt-bakimi' },
        ],
      },
    ],
    faq: [
      {
        q: 'Avène, Bioderma, La Roche-Posay arasında hangisi daha iyi?',
        a: 'Tek bir "en iyi" yoktur; seçim cilt tipinize ve bakım ihtiyacınıza göre değişir. Hassas cilt için Avène ve Toleriane, yağlı/akneye eğilimli cilt için Sébium, Cleanance ve Effaclar, misel temizleme için Bioderma Sensibio öne çıkar. Yukarıdaki karşılaştırma tablosu seçimi kolaylaştırır.',
      },
      {
        q: 'Bu markalar eczane kozmetiği mi?',
        a: 'Evet, üçü de dermokozmetik (eczane kozmetiği) olarak konumlanır. Bunlar kozmetik ürünlerdir; ilaç değildir ve hastalık tedavisi için kullanılmaz.',
      },
      {
        q: 'Hassas cilt için hangi marka uygun?',
        a: 'Avène termal su odaklı sade formülleriyle, La Roche-Posay ise Toleriane serisiyle hassas ciltlere hitap eder. Bioderma Sensibio da hassas ciltler için tercih edilir. Yeni bir ürünü ilk kez kullanırken küçük bir alanda denemek iyi bir alışkanlıktır.',
      },
      {
        q: 'Yağlı ve akneye eğilimli cilt için hangisi?',
        a: 'Üç marka da bu alanda güçlüdür: Bioderma Sébium, Avène Cleanance ve La Roche-Posay Effaclar serileri yağlı ve akneye eğilimli ciltlerin bakımına odaklanır.',
      },
    ],
  },
];

export function getGuide(slug: string): Guide | null {
  return GUIDES.find((g) => g.slug === slug) ?? null;
}
